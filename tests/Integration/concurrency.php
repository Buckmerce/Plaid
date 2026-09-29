<?php

/**
 * Parallel process_payment() for one order from two PHP processes. The Plaid
 * double delays /transfer/intent/create so both workers overlap in time.
 * Exactly one Transfer Intent may be created.
 */

declare(strict_types=1);

$site = (string) getenv('PAYBRIDGE_PLAID_SITE');
if ('' === $site || ! is_file($site . '/wp-load.php') || ! str_starts_with(realpath($site) ?: '', '/tmp/paybridge-')) {
    fwrite(STDERR, "PAYBRIDGE_PLAID_SITE must point to a disposable /tmp/paybridge-* site.\n");
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
$order_id = (int) $eval('require ' . $helpers . '; pbfp_configure(); PayBridge_Test_Plaid_Mock::reset(); $o = pbfp_order("11.11"); PayBridge_Test_Plaid_Mock::fail_next("/transfer/intent/create", "delay:4"); echo $o->get_id();');
if ($order_id < 1) {
    fwrite(STDERR, "Could not create the concurrency test order.\n");
    exit(1);
}
$worker = 'require ' . $helpers . '; WC()->payment_gateways()->init(); $r = WC()->payment_gateways()->payment_gateways()["paybridge_plaid"]->process_payment(' . $order_id . '); echo "RESULT=" . $r["result"];';
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
$creates = (int) $eval('echo count(PayBridge_Test_Plaid_Mock::calls("/transfer/intent/create"));');
$intent = $eval('echo wc_get_order(' . $order_id . ')->get_meta("_pbfp_transfer_intent_id", true);');
sort($results);
if (1 !== $creates || array('failure', 'success') !== $results || '' === $intent) {
    fwrite(STDERR, sprintf("Concurrency FAILED: creates=%d results=%s intent=%s\n", $creates, implode(',', $results), $intent));
    exit(1);
}
// A third, sequential attempt reuses the intent created by the winner.
$eval('require ' . $helpers . '; WC()->payment_gateways()->init(); WC()->payment_gateways()->payment_gateways()["paybridge_plaid"]->process_payment(' . $order_id . ');');
$creates = (int) $eval('echo count(PayBridge_Test_Plaid_Mock::calls("/transfer/intent/create"));');
if (1 !== $creates) {
    fwrite(STDERR, "Concurrency FAILED: sequential retry created another intent.\n");
    exit(1);
}
printf("Success: parallel process_payment created exactly one Transfer Intent (HPOS=%s).\n", getenv('PAYBRIDGE_PLAID_EXPECT_HPOS') ?: '?');
