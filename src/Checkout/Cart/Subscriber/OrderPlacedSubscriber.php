<?php declare(strict_types=1);

namespace Sas\Esd\Checkout\Cart\Subscriber;

use Sas\Esd\Event\EsdDownloadPaymentStatusPaidEvent;
use Sas\Esd\Event\EsdSerialPaymentStatusPaidEvent;
use Sas\Esd\Service\EsdOrderService;
use Sas\Esd\Utils\EsdMailTemplate;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class OrderPlacedSubscriber
{
    /**
     * @var EntityRepositoryInterface
     */
    private $productRepository;

    /**
     * @var EsdOrderService
     */
    private $esdOrderService;

    /**
     * @var SystemConfigService
     */
    private $systemConfigService;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    public function __construct(
        EntityRepositoryInterface $productRepository,
        EsdOrderService $esdOrderService,
        SystemConfigService $systemConfigService,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->productRepository = $productRepository;
        $this->esdOrderService = $esdOrderService;
        $this->systemConfigService = $systemConfigService;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function __invoke(CheckoutOrderPlacedEvent $event): void
    {
        $orderLineItems = $event->getOrder()->getLineItems();

        if ($orderLineItems === null || $event->getOrder()->getAmountTotal() > 0.0) {
            return;
        }

        $productIds = array_filter($orderLineItems->fmap(static function (OrderLineItemEntity $orderLineItem) {
            return $orderLineItem->getProductId();
        }));

        if (empty($productIds)) {
            return;
        }

        $criteria = new Criteria($productIds);
        $criteria->addAssociation('esd.esdMedia');
        $criteria->addFilter(
            new NotFilter(
                NotFilter::CONNECTION_AND,
                [new EqualsFilter('esd.esdMedia.mediaId', null)]
            )
        );

        /** @var ProductCollection $products */
        $products = $this->productRepository->search($criteria, $event->getContext())->getEntities();
        if ($products->count() > 0) {
            $this->esdOrderService->addNewEsdOrders($event->getOrder(), $event->getContext(), $products);
            $templateData = $this->esdOrderService->mailTemplateData($event->getOrder(), $event->getContext());

            if ($this->getSystemConfig(EsdMailTemplate::TEMPLATE_DOWNLOAD_SYSTEM_CONFIG_NAME)
                && !empty($templateData['esdOrderLineItems'])) {
                $event = new EsdDownloadPaymentStatusPaidEvent($event->getContext(), $event->getOrder(), $templateData);
                $this->eventDispatcher->dispatch($event, EsdDownloadPaymentStatusPaidEvent::EVENT_NAME);
            }

            if ($this->getSystemConfig(EsdMailTemplate::TEMPLATE_SERIAL_SYSTEM_CONFIG_NAME)
                && !empty($templateData['esdSerials'])) {
                $event = new EsdSerialPaymentStatusPaidEvent($event->getContext(), $event->getOrder(), $templateData);
                $this->eventDispatcher->dispatch($event, EsdSerialPaymentStatusPaidEvent::EVENT_NAME);
            }
        }
    }

    private function getSystemConfig(string $name): bool
    {
        $config = $this->systemConfigService->get('SasEsd.config.' . $name);
        if (empty($config)) {
            return false;
        }

        return true;
    }
}
