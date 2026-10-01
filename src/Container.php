<?php

declare(strict_types=1);

namespace Buckmerce\Plaid;

use Buckmerce\Plaid\Background\EventSyncService;
use Buckmerce\Plaid\Background\ReconciliationService;
use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Payment\ManualActions;
use Buckmerce\Plaid\Payment\OrderLocator;
use Buckmerce\Plaid\Payment\OrderPaymentProjector;
use Buckmerce\Plaid\Payment\OrderSynchronizer;
use Buckmerce\Plaid\Payment\PaymentAlerts;
use Buckmerce\Plaid\Payment\PaymentAttemptService;
use Buckmerce\Plaid\Payment\PaymentCompletionService;
use Buckmerce\Plaid\Payment\PaymentMonitor;
use Buckmerce\Plaid\Payment\TransferBinder;
use Buckmerce\Plaid\Payment\TransferEventProcessor;
use Buckmerce\Plaid\Persistence\EventCursor;
use Buckmerce\Plaid\Persistence\PaymentLockStore;
use Buckmerce\Plaid\Persistence\RefundStore;
use Buckmerce\Plaid\Persistence\TransferEventStore;
use Buckmerce\Plaid\Plaid\Client\PlaidClientInterface;
use Buckmerce\Plaid\Plaid\Link\LinkTokenService;
use Buckmerce\Plaid\Plaid\PlaidClientFactory;
use Buckmerce\Plaid\Plaid\Refund\TransferRefundService;
use Buckmerce\Plaid\Plaid\Transfer\TransferEventService;
use Buckmerce\Plaid\Plaid\Transfer\TransferService;
use Buckmerce\Plaid\Plaid\TransferIntent\TransferIntentService;
use Buckmerce\Plaid\Plaid\Webhook\VerificationKeyProvider;
use Buckmerce\Plaid\Plaid\Webhook\WebhookVerificationService;
use Buckmerce\Plaid\Refund\RefundEventHandler;
use Buckmerce\Plaid\Refund\RefundService;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Lazily wires services. Constructing it performs no I/O; the Plaid client is
 * created only when a service that needs it is requested.
 */
final class Container
{
    /** @var array<string, object> */
    private array $services = array();

    public function __construct(private ?Settings $settings = null, private ?PlaidClientInterface $client = null)
    {
    }

    public function settings(): Settings
    {
        return $this->settings ??= Settings::load();
    }

    public function logger(): Logger
    {
        return $this->shared(Logger::class, static fn (): Logger => new Logger());
    }

    /** @throws \Buckmerce\Plaid\Exception\ConfigurationException */
    public function client(): PlaidClientInterface
    {
        return $this->client ??= (new PlaidClientFactory())->create($this->settings());
    }

    public function intents(): TransferIntentService
    {
        return $this->shared(TransferIntentService::class, fn (): TransferIntentService => new TransferIntentService($this->client()));
    }

    public function link_tokens(): LinkTokenService
    {
        return $this->shared(LinkTokenService::class, fn (): LinkTokenService => new LinkTokenService($this->client()));
    }

    public function transfers(): TransferService
    {
        return $this->shared(TransferService::class, fn (): TransferService => new TransferService($this->client()));
    }

    public function alerts(): PaymentAlerts
    {
        return $this->shared(PaymentAlerts::class, static fn (): PaymentAlerts => new PaymentAlerts());
    }

    public function projector(): OrderPaymentProjector
    {
        return $this->shared(OrderPaymentProjector::class, fn (): OrderPaymentProjector => new OrderPaymentProjector($this->settings(), $this->logger(), $this->alerts(), $this->monitor(), $this->refunds()));
    }

    public function monitor(): PaymentMonitor
    {
        return $this->shared(PaymentMonitor::class, fn (): PaymentMonitor => new PaymentMonitor($this->locks()));
    }

    public function refund_store(): RefundStore
    {
        return $this->shared(RefundStore::class, static fn (): RefundStore => new RefundStore());
    }

    public function plaid_refunds(): TransferRefundService
    {
        return $this->shared(TransferRefundService::class, fn (): TransferRefundService => new TransferRefundService($this->client()));
    }

    /** Usable without credentials (eligibility, admin screens); Plaid is contacted only when needed. */
    public function refunds(): RefundService
    {
        return $this->shared(RefundService::class, fn (): RefundService => new RefundService(
            $this->settings(),
            fn (): TransferRefundService => $this->plaid_refunds(),
            fn (): TransferService => $this->transfers(),
            $this->refund_store(),
            $this->alerts(),
            $this->logger()
        ));
    }

    public function refund_events(): RefundEventHandler
    {
        return $this->shared(RefundEventHandler::class, fn (): RefundEventHandler => new RefundEventHandler(
            $this->refunds(),
            $this->refund_store(),
            new OrderLocator($this->locks()),
            fn (): TransferRefundService => $this->plaid_refunds(),
            fn (): TransferService => $this->transfers(),
            $this->logger()
        ));
    }

    public function manual_actions(): ManualActions
    {
        return $this->shared(ManualActions::class, fn (): ManualActions => new ManualActions(
            $this->settings(),
            $this->transfers(),
            $this->intents(),
            $this->binder(),
            $this->locks(),
            $this->alerts(),
            $this->logger()
        ));
    }

    public function binder(): TransferBinder
    {
        return $this->shared(TransferBinder::class, fn (): TransferBinder => new TransferBinder($this->transfers(), $this->projector(), $this->locks(), $this->monitor()));
    }

    public function locks(): PaymentLockStore
    {
        return $this->shared(PaymentLockStore::class, static fn (): PaymentLockStore => new PaymentLockStore());
    }

    public function attempts(): PaymentAttemptService
    {
        return $this->shared(PaymentAttemptService::class, fn (): PaymentAttemptService => new PaymentAttemptService(
            $this->settings(),
            $this->intents(),
            $this->link_tokens(),
            $this->binder(),
            $this->projector(),
            $this->locks(),
            $this->logger(),
            $this->monitor()
        ));
    }

    public function completion(): PaymentCompletionService
    {
        return $this->shared(PaymentCompletionService::class, fn (): PaymentCompletionService => new PaymentCompletionService(
            $this->settings(),
            $this->intents(),
            $this->binder(),
            $this->projector(),
            $this->logger()
        ));
    }

    public function synchronizer(): OrderSynchronizer
    {
        return $this->shared(OrderSynchronizer::class, fn (): OrderSynchronizer => new OrderSynchronizer(
            $this->settings(),
            $this->intents(),
            $this->transfers(),
            $this->binder(),
            $this->projector()
        ));
    }

    public function event_processor(): TransferEventProcessor
    {
        return $this->shared(TransferEventProcessor::class, fn (): TransferEventProcessor => new TransferEventProcessor(
            new OrderLocator($this->locks()),
            $this->intents(),
            $this->transfers(),
            $this->binder(),
            $this->projector(),
            $this->logger(),
            $this->refund_events()
        ));
    }

    public function event_sync(): EventSyncService
    {
        return $this->shared(EventSyncService::class, fn (): EventSyncService => new EventSyncService(
            $this->settings()->account_scope(),
            new TransferEventService($this->client()),
            new TransferEventStore(),
            new EventCursor(),
            $this->event_processor(),
            $this->logger()
        ));
    }

    public function reconciliation(): ReconciliationService
    {
        return $this->shared(ReconciliationService::class, fn (): ReconciliationService => new ReconciliationService(
            $this->settings(),
            $this->event_sync(),
            $this->synchronizer(),
            $this->locks(),
            $this->logger(),
            $this->monitor(),
            $this->refunds()
        ));
    }

    public function webhook_verifier(): WebhookVerificationService
    {
        return $this->shared(WebhookVerificationService::class, fn (): WebhookVerificationService => new WebhookVerificationService(new VerificationKeyProvider($this->client())));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T   $factory
     * @return T
     */
    private function shared(string $id, callable $factory): object
    {
        if (! isset($this->services[$id])) {
            $this->services[$id] = $factory();
        }
        /** @var T */
        return $this->services[$id];
    }
}
