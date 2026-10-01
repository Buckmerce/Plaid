<?php

/**
 * Parallel process_payment() for one order from two PHP processes. The Plaid
 * double delays /transfer/intent/create so both workers overlap in time.
 * Exactly one Transfer Intent may be created.
 */

declare(strict_types=1);

$site = (string) getenv('BUCKMERCE_PLAID_SITE');
if ('' === $site || ! is_file($site . '/wp-load.php') || ! str_starts_with(realpath($site) ?: '', '/tmp/buckmerce-')) {
    fwrite(STDERR, "BUCKMERCE_PLAID_SITE must point to a disposable /tmp/buckmerce-* site.\n");
    exit(1);
}
$wp = array('wp', '--path=' . $site, '--no-color');
$run = static function (array $command): array {
    $pipes = array();
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    return array($process, $pipes);
};
$eval = static fn (string $code): string => trim((string) shell_exec(implode(' ', array_map('escapeshellarg', array('wp', '--path=' . $site, '--no-color', 'eval', $code)))));

$helpers = escapeshellarg(__DIR__ . '/helpers.php');
$order_id = (int) $eval('require ' . $helpers . '; bmfp_configure(); bmfp_reset_world(); $o = bmfp_order("11.11"); Buckmerce_Test_Plaid_Mock::fail_next("/transfer/intent/create", "delay:4"); echo $o->get_id();');
if ($order_id < 1) {
    fwrite(STDERR, "Could not create the concurrency test order.\n");
    exit(1);
}
$worker = 'require ' . $helpers . '; WC()->payment_gateways()->init(); $r = WC()->payment_gateways()->payment_gateways()["buckmerce_plaid"]->process_payment(' . $order_id . '); echo "RESULT=" . $r["result"];';
$workers = array();
for ($i = 0; $i < 2; ++$i) {
    $workers[] = $run(array_merge($wp, array('eval', $worker)));
    usleep(300000);
}
$results = array();
foreach ($workers as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($process);
    preg_match('/RESULT=(\w+)/', $output, $match);
    $results[] = $match[1] ?? 'error: ' . substr($output, 0, 300);
}
$creates = (int) $eval('echo count(Buckmerce_Test_Plaid_Mock::calls("/transfer/intent/create"));');
$intent = $eval('echo wc_get_order(' . $order_id . ')->get_meta("_bmfp_transfer_intent_id", true);');
sort($results);
if (1 !== $creates || array('failure', 'success') !== $results || '' === $intent) {
    fwrite(STDERR, sprintf("Concurrency FAILED: creates=%d results=%s intent=%s\n", $creates, implode(',', $results), $intent));
    exit(1);
}
// A third, sequential attempt reuses the intent created by the winner.
$eval('require ' . $helpers . '; WC()->payment_gateways()->init(); WC()->payment_gateways()->payment_gateways()["buckmerce_plaid"]->process_payment(' . $order_id . ');');
$creates = (int) $eval('echo count(Buckmerce_Test_Plaid_Mock::calls("/transfer/intent/create"));');
if (1 !== $creates) {
    fwrite(STDERR, "Concurrency FAILED: sequential retry created another intent.\n");
    exit(1);
}

/** Runs $code in two overlapping PHP processes and returns their RESULT= values, sorted. */
$parallel = static function (string $code) use ($run, $wp): array {
    $workers = array();
    for ($i = 0; $i < 2; ++$i) {
        $workers[] = $run(array_merge($wp, array('eval', $code)));
        usleep(300000);
    }
    $results = array();
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($process);
        preg_match('/RESULT=([\w-]+)/', $output, $match);
        $results[] = $match[1] ?? 'error: ' . substr($output, 0, 300);
    }
    sort($results);
    return $results;
};
$fail = static function (string $message): void {
    fwrite(STDERR, 'Concurrency FAILED: ' . $message . "\n");
    exit(1);
};

// Two completion callbacks at the same time: one binds the transfer, the other is told to retry.
$paid_id = (int) $eval('require ' . $helpers . '; $o = wc_get_order(' . $order_id . '); $t = (new Buckmerce\\Plaid\\Container())->attempts()->issue_link_token($o); Buckmerce_Test_Plaid_Mock::authorize($t->token); Buckmerce_Test_Plaid_Mock::fail_next("/transfer/intent/get", "delay:3"); echo $o->get_id();');
// Exactly like the /complete controller: a busy payment is reported as "unverified" and retried by the browser.
$results = $parallel('require ' . $helpers . '; try { $r = (new Buckmerce\\Plaid\\Container())->completion()->complete(wc_get_order(' . $paid_id . ')); echo "RESULT=" . $r->status; } catch (Buckmerce\\Plaid\\Exception\\PaymentAttemptBusyException $e) { echo "RESULT=unverified"; }');
$bound = (int) $eval('require ' . $helpers . '; echo bmfp_note_count(wc_get_order(' . $paid_id . '), "Plaid transfer created");');
if (array('submitted', 'unverified') !== $results || 1 !== $bound) {
    $fail(sprintf('concurrent completion results=%s transfer notes=%d', implode(',', $results), $bound));
}

// Two event workers at the same time: one processes, the other backs off; every event is applied once.
$eval('require ' . $helpers . '; $o = wc_get_order(' . $paid_id . '); foreach (array("posted", "settled", "funds_available") as $t) { Buckmerce_Test_Plaid_Mock::add_event($o->get_meta("_bmfp_transfer_id", true), $t); } Buckmerce_Test_Plaid_Mock::fail_next("/transfer/event/sync", "delay:3");');
$results = $parallel('require ' . $helpers . '; $r = (new Buckmerce\\Plaid\\Container())->event_sync()->run(); echo "RESULT=" . $r["status"];');
$eval('require ' . $helpers . '; (new Buckmerce\\Plaid\\Container())->event_sync()->run();');
$state = $eval('require ' . $helpers . '; $o = wc_get_order(' . $paid_id . '); echo $o->get_meta("_bmfp_payment_state", true), "|", bmfp_note_count($o, "funds available"), "|", $o->is_paid() ? "paid" : "unpaid";');
if (array('busy', 'ok') !== $results || 'funds_available|1|paid' !== $state) {
    $fail(sprintf('parallel event workers results=%s state=%s', implode(',', $results), $state));
}

// Two refunds of the same amount at the same time (double click, two tabs): exactly one Plaid refund.
$eval('require ' . $helpers . '; Buckmerce_Test_Plaid_Mock::fail_next("/transfer/refund/create", "delay:3");');
$results = $parallel('require ' . $helpers . '; $r = bmfp_wc_refund(wc_get_order(' . $paid_id . '), "5.00"); echo "RESULT=" . ($r instanceof WC_Order_Refund ? "refunded" : "refused");');
$refunds = $eval('require ' . $helpers . '; $o = wc_get_order(' . $paid_id . '); echo count(Buckmerce_Test_Plaid_Mock::calls("/transfer/refund/create")), "|", count($o->get_refunds()), "|", count(bmfp_refund_rows($o));');
if (array('refunded', 'refused') !== $results || '1|1|1' !== $refunds) {
    $fail(sprintf('parallel refunds results=%s creates|wc_refunds|rows=%s', implode(',', $results), $refunds));
}

printf("Success: parallel process_payment created exactly one Transfer Intent; concurrent completions, event workers and refunds each had exactly one effect (HPOS=%s).\n", getenv('BUCKMERCE_PLAID_EXPECT_HPOS') ?: '?');
