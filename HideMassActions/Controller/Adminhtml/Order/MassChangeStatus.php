<?php
namespace MagoArab\HideMassActions\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use Magento\Sales\Model\Order;

/**
 * Mass order status update controller with optimized performance
 */
class MassChangeStatus extends Action
{
    /**
     * @var Filter
     */
    protected $filter;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * Batch size for processing orders
     *
     * @var int
     */
    protected $batchSize = 50;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->objectManager = $context->getObjectManager();
    }
	/**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            // Optimize server settings for large operations
            $this->optimizeServerSettings();
            
            // Get selected orders from the UI
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $collectionSize = $collection->getSize();
            
            // Get requested status from parameters
            $status = $this->getRequest()->getParam('status');
            
            if (!$status) {
                throw new LocalizedException(__('No status specified.'));
            }

            $orderUpdated = 0;
            $orderErrors = 0;
            $orderIds = [];

            // Split orders into batches for better performance
            $batchedCollection = $this->splitIntoBatches($collection);
            
            $this->getLogger()->info(sprintf(
                'Starting mass status update to "%s" for %d orders split into %d batches',
                $status,
                $collectionSize,
                count($batchedCollection)
            ));

            // Prepare arrays for collecting update data
            $directOrderUpdates = [];
            $directGridUpdates = [];
            $ordersToProcessManually = [];
            
            // Process each batch separately
            foreach ($batchedCollection as $batchIndex => $batch) {
                $batchOrderIds = [];
                
                $this->getLogger()->info(sprintf(
                    'Processing batch %d of %d with %d orders',
                    $batchIndex + 1,
                    count($batchedCollection),
                    count($batch)
                ));
                
                foreach ($batch as $order) {
                    try {
                        $orderId = $order->getId();
                        $incrementId = $order->getIncrementId();
                        $oldStatus = $order->getStatus();
                        $oldState = $order->getState();
                        
                        $batchOrderIds[] = $orderId;
                        $orderIds[] = $orderId;
                        
                        // Log processing information
                        $this->getLogger()->info(sprintf(
                            'Processing order #%s from %s to %s',
                            $incrementId,
                            $oldStatus,
                            $status
                        ));
						// Determine update strategy based on requested status
                        switch ($status) {
                            case 'complete':
                                // For "complete" status
                                if ($order->canInvoice() || $order->canShip()) {
                                    // Collect orders that need special processing for later
                                    $ordersToProcessManually[] = $order;
                                } else {
                                    // If no special processing needed, use proper model update
                                    try {
                                        $orderRepository = $this->objectManager->get('Magento\Sales\Api\OrderRepositoryInterface');
                                        $currentOrder = $orderRepository->get($orderId);
                                        
                                        $state = $this->getOrderStateForStatus($status);
                                        $currentOrder->setState($state);
                                        $currentOrder->setStatus($status);
                                        $currentOrder->addCommentToStatusHistory(
                                            __('Status updated via Mass Action'),
                                            false,
                                            false
                                        );
                                        
                                        // Save using repository to trigger all necessary events
                                        $orderRepository->save($currentOrder);
                                        
                                        $this->getLogger()->info(sprintf('Updated order #%s to %s using proper model', 
                                            $currentOrder->getIncrementId(), $status));
                                    } catch (\Exception $e) {
                                        $this->getLogger()->warning(sprintf('Could not update order #%s: %s', 
                                            $incrementId, $e->getMessage()));
                                        // Fallback to direct update if model method fails
                                        $state = $this->getOrderStateForStatus($status);
                                        $directOrderUpdates[$orderId] = [
                                            'status' => $status,
                                            'state' => $state,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ];
                                        $directGridUpdates[$orderId] = [
                                            'status' => $status,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ];
                                    }
                                }
                                break;
                                
                            case 'canceled':
                                // For "canceled" status
                                if ($order->canCancel()) {
                                    // Collect orders that can be canceled normally for later
                                    $ordersToProcessManually[] = $order;
                                } else {
                                    // If cannot be canceled normally, use proper model update
                                    try {
                                        $orderRepository = $this->objectManager->get('Magento\Sales\Api\OrderRepositoryInterface');
                                        $currentOrder = $orderRepository->get($orderId);
                                        
                                        $state = $this->getOrderStateForStatus($status);
                                        $currentOrder->setState($state);
                                        $currentOrder->setStatus($status);
                                        $currentOrder->addCommentToStatusHistory(
                                            __('Status updated via Mass Action'),
                                            false,
                                            false
                                        );
                                        
                                        // Save using repository to trigger all necessary events
                                        $orderRepository->save($currentOrder);
                                        
                                        $this->getLogger()->info(sprintf('Updated order #%s to %s using proper model', 
                                            $currentOrder->getIncrementId(), $status));
                                    } catch (\Exception $e) {
                                        $this->getLogger()->warning(sprintf('Could not update order #%s: %s', 
                                            $incrementId, $e->getMessage()));
                                        // Fallback to direct update if model method fails
                                        $state = $this->getOrderStateForStatus($status);
                                        $directOrderUpdates[$orderId] = [
                                            'status' => $status,
                                            'state' => $state,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ];
                                        $directGridUpdates[$orderId] = [
                                            'status' => $status,
                                            'updated_at' => date('Y-m-d H:i:s')
                                        ];
                                    }
                                }
                                break;
								default:
                                // For other statuses, use proper order model update
                                try {
                                    $orderRepository = $this->objectManager->get('Magento\Sales\Api\OrderRepositoryInterface');
                                    $currentOrder = $orderRepository->get($orderId);
                                    
                                    $state = $this->getOrderStateForStatus($status);
                                    $currentOrder->setState($state);
                                    $currentOrder->setStatus($status);
                                    $currentOrder->addCommentToStatusHistory(
                                        __('Status updated via Mass Action'),
                                        false,
                                        false
                                    );
                                    
                                    // Save using repository to trigger all necessary events
                                    $orderRepository->save($currentOrder);
                                    
                                    $this->getLogger()->info(sprintf('Updated order #%s to %s using proper model', 
                                        $currentOrder->getIncrementId(), $status));
                                } catch (\Exception $e) {
                                    $this->getLogger()->warning(sprintf('Could not update order #%s: %s', 
                                        $incrementId, $e->getMessage()));
                                    // Fallback to direct update if model method fails
                                    $state = $this->getOrderStateForStatus($status);
                                    $directOrderUpdates[$orderId] = [
                                        'status' => $status,
                                        'state' => $state,
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ];
                                    $directGridUpdates[$orderId] = [
                                        'status' => $status,
                                        'updated_at' => date('Y-m-d H:i:s')
                                    ];
                                }
                                break;
                        }
                        
                        $orderUpdated++;
                        
                    } catch (\Exception $e) {
                        $this->getLogger()->error(sprintf(
                            'Error updating order #%s: %s',
                            $incrementId,
                            $e->getMessage()
                        ));
                        $orderErrors++;
                    }
                }

                // Perform direct updates for the current batch
                if (!empty($batchOrderIds)) {
                    $this->directlyUpdateBatchOrders($batchOrderIds, $directOrderUpdates, $directGridUpdates);
                }
                
                // Clear memory after each batch
                $this->clearMemory(true);
            }
			// Process orders that need special handling
            $this->getLogger()->info(sprintf(
                'Processing %d orders that require special handling',
                count($ordersToProcessManually)
            ));
            
            foreach ($ordersToProcessManually as $order) {
                try {
                    $incrementId = $order->getIncrementId();
                    
                    // Process order based on requested status
                    if ($status === 'complete') {
                        $this->completeOrder($order);
                    } elseif ($status === 'canceled') {
                        $this->cancelOrder($order);
                    }
                    
                    $this->getLogger()->info(sprintf('Successfully processed order #%s', $incrementId));
                } catch (\Exception $e) {
                    $this->getLogger()->error(sprintf(
                        'Error processing order #%s: %s',
                        $order->getIncrementId(),
                        $e->getMessage()
                    ));
                    $orderErrors++;
                    $orderUpdated--;
                }
                
                // Clear memory after each order
                $this->clearMemory();
            }
            
            // Add comments to updated orders
            $this->addCommentsToOrders($orderIds, $status);
            
            // Update each order grid status directly using simple method
            foreach ($orderIds as $orderId) {
                $this->simpleUpdateGridStatus($orderId, $status);
            }

            // Clean specific caches
            try {
                $cache = $this->objectManager->get('Magento\Framework\App\Cache\TypeListInterface');
                $cache->cleanType('collections');
                $cache->cleanType('config');
                $cache->cleanType('full_page');
            } catch (\Exception $e) {
                // Ignore cache errors
            }
            
            // Fix invalid indexer message without full reindex
            $this->fixInvalidIndexerMessage();
            
            if ($orderUpdated) {
                $this->messageManager->addSuccessMessage(
                    __('A total of %1 order(s) have been updated.', $orderUpdated)
                );
            }
            
            if ($orderErrors) {
                $this->messageManager->addErrorMessage(
                    __('A total of %1 order(s) cannot be updated.', $orderErrors)
                );
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->getLogger()->critical($e);
            $this->messageManager->addExceptionMessage(
                $e,
                __('Something went wrong while updating order status.')
            );
        }
        
        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        return $resultRedirect->setPath('sales/order/index');
    }
	/**
     * Optimize server settings for large operations
     *
     * @return void
     */
    private function optimizeServerSettings()
    {
        // Increase execution time limit (if allowed)
        if (function_exists('set_time_limit')) {
            set_time_limit(0); // No time limit
        }
        
        // Increase memory limit (if allowed)
        if (function_exists('ini_set')) {
            ini_set('memory_limit', '2G'); // Increase memory limit to 2GB
        }
    }

    /**
     * Update batch orders using proper Magento models
     *
     * @param array $orderIds
     * @param array $orderUpdates
     * @param array $gridUpdates
     * @return void
     */
    private function directlyUpdateBatchOrders($orderIds, $orderUpdates, $gridUpdates)
    {
        if (empty($orderIds)) {
            return;
        }
        
        try {
            $orderRepository = $this->objectManager->get('Magento\Sales\Api\OrderRepositoryInterface');
            $searchCriteriaBuilder = $this->objectManager->get('Magento\Framework\Api\SearchCriteriaBuilder');
            
            // Load all orders in batch
            $searchCriteria = $searchCriteriaBuilder
                ->addFilter('entity_id', $orderIds, 'in')
                ->create();
                
            $orders = $orderRepository->getList($searchCriteria)->getItems();
            
            foreach ($orders as $order) {
                $orderId = $order->getId();
                
                if (isset($orderUpdates[$orderId])) {
                    $updateData = $orderUpdates[$orderId];
                    
                    if (isset($updateData['status'])) {
                        $order->setStatus($updateData['status']);
                    }
                    if (isset($updateData['state'])) {
                        $order->setState($updateData['state']);
                    }
                    
                    $order->addCommentToStatusHistory(
                        __('Status updated via Mass Action'),
                        false,
                        false
                    );
                    
                    // Save using repository to ensure proper handling
                    $orderRepository->save($order);
                }
            }
            
            $this->getLogger()->info(sprintf('Directly updated %d orders in database', count($orders)));
            
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to update orders using model: ' . $e->getMessage());
            
            // Fallback to direct database update if model method fails
            try {
                $connection = $this->getConnection();
                $orderTable = $this->getTableName('sales_order');
                $gridTable = $this->getTableName('sales_order_grid');
                
                foreach ($orderIds as $orderId) {
                    if (isset($orderUpdates[$orderId])) {
                        if (!isset($orderUpdates[$orderId]['updated_at'])) {
                            $orderUpdates[$orderId]['updated_at'] = date('Y-m-d H:i:s');
                        }
                        
                        $connection->update(
                            $orderTable,
                            $orderUpdates[$orderId],
                            ['entity_id = ?' => $orderId]
                        );
                        
                        if (isset($gridUpdates[$orderId])) {
                            if (!isset($gridUpdates[$orderId]['updated_at'])) {
                                $gridUpdates[$orderId]['updated_at'] = date('Y-m-d H:i:s');
                            }
                            
                            $connection->update(
                                $gridTable,
                                $gridUpdates[$orderId],
                                ['entity_id = ?' => $orderId]
                            );
                        }
                    }
                }
                
                $this->getLogger()->info('Used fallback direct database update');
            } catch (\Exception $fallbackError) {
                $this->getLogger()->error('Both model and direct update failed: ' . $fallbackError->getMessage());
                throw $fallbackError;
            }
        }
    }
	/**
     * Add comments to updated orders
     *
     * @param array $orderIds
     * @param string $status
     * @return void
     */
    private function addCommentsToOrders($orderIds, $status)
    {
        if (empty($orderIds)) {
            return;
        }
        
        try {
            $connection = $this->getConnection();
            $historyTable = $this->getTableName('sales_order_status_history');
            $now = date('Y-m-d H:i:s');
            
            // Prepare data for bulk insert
            $insertData = [];
            foreach ($orderIds as $orderId) {
                $insertData[] = [
                    'parent_id' => $orderId,
                    'entity_name' => 'order',
                    'status' => $status,
                    'comment' => 'Status updated via Mass Action',
                    'is_customer_notified' => 0,
                    'is_visible_on_front' => 0,
                    'created_at' => $now
                ];
            }
            
            // Bulk insert status comments
            if (!empty($insertData)) {
                $connection->insertMultiple($historyTable, $insertData);
                $this->getLogger()->info(sprintf('Added history comments to %d orders', count($insertData)));
            }
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to add comments to orders: ' . $e->getMessage());
            // Ignore this error as it's not critical to the process
        }
    }

    /**
     * Simple direct update of grid status
     *
     * @param int $orderId
     * @param string $status
     * @return void
     */
    private function simpleUpdateGridStatus($orderId, $status)
    {
        try {
            $connection = $this->getConnection();
            $gridTable = $this->getTableName('sales_order_grid');
            
            // Make a very simple update
            $connection->query(
                "UPDATE `$gridTable` SET status = '$status', updated_at = NOW() WHERE entity_id = $orderId"
            );
        } catch (\Exception $e) {
            // Ignore errors
        }
    }

    /**
     * Fix invalid indexer message without full reindex
     * Updated to avoid unnecessary warning logs
     *
     * @return void
     */
    private function fixInvalidIndexerMessage()
    {
        try {
            // Skip checking individual indexers by name to avoid log warnings
            // Go directly to database update which is faster and more reliable
            $connection = $this->getConnection();
            $indexerStateTable = $this->getTableName('indexer_state');
            
            if ($connection->isTableExists($indexerStateTable)) {
                // Update all sales related indexers statuses at once
                $updated = $connection->update(
                    $indexerStateTable,
                    ['status' => 'valid'],
                    ['status = ?' => 'invalid', 'indexer_id LIKE ?' => '%sales%']
                );
                
                if ($updated > 0) {
                    $this->getLogger()->info("Fixed {$updated} sales indexers directly in database");
                }
            }
            
            // Only if direct DB update didn't work, try the object method
            // But skip trying to load non-existent indexers by hardcoded names
            $indexerCollection = $this->objectManager->create('Magento\Indexer\Model\Indexer\Collection');
            foreach ($indexerCollection as $indexer) {
                $indexerId = $indexer->getId();
                if ((strpos($indexerId, 'sales') !== false || strpos($indexerId, 'order') !== false) && 
                    $indexer->getStatus() == \Magento\Framework\Indexer\StateInterface::STATUS_INVALID) {
                    
                    try {
                        $indexer->getState()->setStatus(\Magento\Framework\Indexer\StateInterface::STATUS_VALID);
                        $indexer->getState()->save();
                        $this->getLogger()->info('Fixed status for indexer ' . $indexerId);
                    } catch (\Exception $e) {
                        // Don't log warnings, just continue silently
                    }
                }
            }
        } catch (\Exception $e) {
            // Only log critical errors
            $this->getLogger()->error('Critical error fixing indexers: ' . $e->getMessage());
        }
    }
	/**
     * Split collection into batches
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Collection $collection
     * @return array
     */
    private function splitIntoBatches($collection)
    {
        $batchedCollection = [];
        $currentBatch = 0;
        $i = 0;

        foreach ($collection as $order) {
            if (!isset($batchedCollection[$currentBatch])) {
                $batchedCollection[$currentBatch] = [];
            }
            
            $batchedCollection[$currentBatch][] = $order;
            $i++;
            
            if ($i >= $this->batchSize) {
                $currentBatch++;
                $i = 0;
            }
        }
        
        return $batchedCollection;
    }

    /**
     * Clear memory
     *
     * @param bool $forceClearCache
     * @return void
     */
    private function clearMemory($forceClearCache = false)
    {
        // Unregister objects
        $registry = $this->objectManager->get('Magento\Framework\Registry');
        $registry->unregister('current_order');
        $registry->unregister('current_invoice');
        $registry->unregister('current_shipment');
        
        if ($forceClearCache) {
            // Clean PHP memory
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }

    /**
     * Get logger
     *
     * @return \Psr\Log\LoggerInterface
     */
    private function getLogger()
    {
        return $this->objectManager->get('Psr\Log\LoggerInterface');
    }

    /**
     * Get database connection
     *
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function getConnection()
    {
        return $this->objectManager->get('Magento\Framework\App\ResourceConnection')->getConnection();
    }

    /**
     * Get table name with prefix
     *
     * @param string $tableName
     * @return string
     */
    private function getTableName($tableName)
    {
        return $this->objectManager->get('Magento\Framework\App\ResourceConnection')->getTableName($tableName);
    }
	/**
     * Complete an order using the official Magento method
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function completeOrder($order)
    {
        $incrementId = $order->getIncrementId();
        
        try {
            // Reload order to ensure we have fresh data
            $orderRepository = $this->objectManager->get('Magento\Sales\Api\OrderRepositoryInterface');
            $order = $orderRepository->get($order->getId());
            
            // Check if order is already complete
            if ($order->getState() === Order::STATE_COMPLETE) {
                $this->getLogger()->info('Order #' . $incrementId . ' is already complete');
                return;
            }
            
            // 1. Create invoice if needed and possible
            if ($order->canInvoice()) {
                $this->createInvoiceForOrder($order);
                // Reload order after invoice creation
                $order = $orderRepository->get($order->getId());
            }
            
            // 2. Create shipment if needed and possible
            if ($order->canShip()) {
                $this->createShipmentForOrder($order);
                // Reload order after shipment creation
                $order = $orderRepository->get($order->getId());
            }
            
            // 3. If order can be completed through normal Magento process
            if ($order->getState() !== Order::STATE_COMPLETE) {
                // Use order state service to properly set the complete state
                $order->setState(Order::STATE_COMPLETE);
                $order->setStatus('complete');
                
                // Add status history
                $order->addCommentToStatusHistory(
                    __('Order completed via Mass Action'),
                    false,
                    false
                );
                
                // Save using repository to ensure all hooks and events are triggered
                $orderRepository->save($order);
            }
            
            // Force grid status update
            $this->simpleUpdateGridStatus($order->getId(), 'complete');
            
            $this->getLogger()->info('Order #' . $incrementId . ' completed successfully');
            
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to complete order #' . $incrementId . ': ' . $e->getMessage());
            throw $e;
        }
    }
	/**
     * Create invoice for order using proper Magento services
     *
     * @param \Magento\Sales\Model\Order $order
     * @return \Magento\Sales\Model\Order\Invoice|null
     */
    private function createInvoiceForOrder($order)
    {
        try {
            $invoiceService = $this->objectManager->get('Magento\Sales\Model\Service\InvoiceService');
            $invoice = $invoiceService->prepareInvoice($order);
            
            if (!$invoice || !$invoice->getTotalQty()) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Cannot create invoice without items for order #%1', $order->getIncrementId())
                );
            }
            
            // Set capture case
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->register();
            
            // Save using transaction to ensure data integrity
            $transactionSave = $this->objectManager->create('Magento\Framework\DB\Transaction');
            $transactionSave->addObject($invoice)->addObject($order)->save();
            
            $this->getLogger()->info('Invoice created for order #' . $order->getIncrementId());
            return $invoice;
            
        } catch (\Exception $e) {
            $this->getLogger()->warning('Could not create invoice for order #' . $order->getIncrementId() . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Create shipment for order using proper Magento services
     *
     * @param \Magento\Sales\Model\Order $order
     * @return \Magento\Sales\Model\Order\Shipment|null
     */
    private function createShipmentForOrder($order)
    {
        try {
            $orderConverter = $this->objectManager->get('Magento\Sales\Model\Convert\Order');
            
            $shipment = $orderConverter->toShipment($order);
            
            // Add items to shipment
            foreach ($order->getAllItems() as $orderItem) {
                if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                    continue;
                }
                
                $qtyShipped = $orderItem->getQtyToShip();
                $shipmentItem = $orderConverter->itemToShipmentItem($orderItem);
                $shipmentItem->setQty($qtyShipped);
                $shipment->addItem($shipmentItem);
            }
            
            $shipment->register();
            
            // Save using transaction
            $transactionSave = $this->objectManager->create('Magento\Framework\DB\Transaction');
            $transactionSave->addObject($shipment)->addObject($order)->save();
            
            $this->getLogger()->info('Shipment created for order #' . $order->getIncrementId());
            return $shipment;
            
        } catch (\Exception $e) {
            $this->getLogger()->warning('Could not create shipment for order #' . $order->getIncrementId() . ': ' . $e->getMessage());
            return null;
        }
    }
	/**
     * Cancel order
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    private function cancelOrder($order)
    {
        $incrementId = $order->getIncrementId();
        
        if (!$order->canCancel()) {
            $this->getLogger()->info('Order #' . $incrementId . ' cannot be canceled normally');
            
            // Try to cancel the order using direct method
            try {
                // Update order status directly
                $connection = $this->getConnection();
                $orderTable = $this->getTableName('sales_order');
                $gridTable = $this->getTableName('sales_order_grid');
                
                // Update order
                $connection->update(
                    $orderTable,
                    [
                        'status' => 'canceled',
                        'state' => Order::STATE_CANCELED,
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    ['entity_id = ?' => $order->getId()]
                );
                
                // Update grid
                $connection->update(
                    $gridTable,
                    [
                        'status' => 'canceled',
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    ['entity_id = ?' => $order->getId()]
                );
                
                $this->getLogger()->info('Order #' . $incrementId . ' canceled using direct update');
                return;
            } catch (\Exception $e) {
                $this->getLogger()->error('Failed to cancel order #' . $incrementId . ' using direct update: ' . $e->getMessage());
                throw $e;
            }
        }
        
        try {
            // Use standard cancellation method
            $orderManagement = $this->objectManager->create('Magento\Sales\Api\OrderManagementInterface');
            $orderManagement->cancel($order->getId());
            $this->getLogger()->info('Order #' . $incrementId . ' canceled successfully');
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to cancel order #' . $incrementId . ': ' . $e->getMessage());
            throw $e;
        }
    }
	/**
     * Get appropriate order state for status
     *
     * @param string $status
     * @return string
     */
    private function getOrderStateForStatus($status)
    {
        $statusStateMap = [
            // Custom status mappings
            'preparingb' => Order::STATE_PROCESSING,
            'preparinga' => Order::STATE_PROCESSING,
            'deliveredtodayc' => Order::STATE_COMPLETE,
            
            // Standard Magento status mappings
            'pending' => Order::STATE_NEW,
            'pending_payment' => Order::STATE_PENDING_PAYMENT,
            'processing' => Order::STATE_PROCESSING,
            'complete' => Order::STATE_COMPLETE,
            'closed' => Order::STATE_CLOSED,
            'canceled' => Order::STATE_CANCELED,
            'holded' => Order::STATE_HOLDED,
            'payment_review' => Order::STATE_PAYMENT_REVIEW,
            'fraud' => Order::STATE_PAYMENT_REVIEW
        ];
        
        // Return appropriate state, or default state if no mapping found
        return isset($statusStateMap[$status]) ? $statusStateMap[$status] : Order::STATE_PROCESSING;
    }

    /**
     * Process inventory for completed or canceled orders
     * 
     * @param string $status
     * @param array $orderIds
     * @return void
     */
    private function processInventoryForOrders($status, $orderIds)
    {
        if (empty($orderIds) || !in_array($status, ['complete', 'canceled'])) {
            return;
        }
        
        try {
            // Get inventory management service
            $inventoryManagement = $this->objectManager->get('Magento\CatalogInventory\Api\StockManagementInterface');
            
            // Update product stock status for these orders
            if ($status === 'complete') {
                // For completion, we need to confirm inventory deduction
                foreach ($orderIds as $orderId) {
                    try {
                        $order = $this->objectManager->create('Magento\Sales\Model\OrderRepository')->get($orderId);
                        
                        foreach ($order->getAllItems() as $orderItem) {
                            if ($orderItem->getProductType() === 'simple' && !$orderItem->getParentItemId()) {
                                // Update product stock status to avoid inventory errors
                                $stockItem = $this->objectManager->create('Magento\CatalogInventory\Api\StockRegistryInterface')
                                    ->getStockItem($orderItem->getProductId());
                                
                                if ($stockItem->getItemId()) {
                                    // Set product as in stock
                                    $stockItem->setIsInStock(true);
                                    $stockItem->setQty($stockItem->getQty());
                                    $stockItem->save();
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        $this->getLogger()->warning('Could not process inventory for order #' . $orderId . ': ' . $e->getMessage());
                    }
                }
            } elseif ($status === 'canceled') {
                // For cancellation, we need to return inventory
                foreach ($orderIds as $orderId) {
                    try {
                        // Use revert inventory interface
                        $this->objectManager->create('Magento\CatalogInventory\Model\ResourceModel\Stock')
                            ->revertProductsSale([], $orderId);
                    } catch (\Exception $e) {
                        $this->getLogger()->warning('Could not revert inventory for order #' . $orderId . ': ' . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to process inventory updates: ' . $e->getMessage());
        }
    }
	/**
     * Repair indexer issues
     * 
     * @return void
     */
    private function repairIndexers()
    {
        try {
            // Get indexer list
            $indexerFactory = $this->objectManager->create('Magento\Indexer\Model\IndexerFactory');
            $indexerListProvider = $this->objectManager->create('Magento\Indexer\Model\Indexer\CollectionFactory')->create();
            
            // Identify order-related indexers
            $orderRelatedIndexers = [
                'sales_order_grid',
                'sales_grid_order_grid',
                'catalog_product_price',
                'cataloginventory_stock',
                'inventory'
            ];
            
            // Process each indexer
            foreach ($orderRelatedIndexers as $indexerId) {
                try {
                    $indexer = $indexerFactory->create();
                    $indexer->load($indexerId);
                    
                    // Reset indexer to remove any lock
                    $indexer->getState()->setStatus('invalid');
                    $indexer->getState()->save();
                    
                    $this->getLogger()->info('Successfully reset indexer: ' . $indexerId);
                } catch (\Exception $e) {
                    $this->getLogger()->warning('Failed to reset indexer ' . $indexerId . ': ' . $e->getMessage());
                }
            }
            
            // Clean cache
            $cache = $this->objectManager->get('Magento\Framework\App\Cache\TypeListInterface');
            $cache->cleanType('collections');
            $cache->cleanType('config');
            $cache->cleanType('layout');
            $cache->cleanType('full_page');
            
            $this->getLogger()->info('Successfully cleaned cache to improve indexer functionality');
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to repair indexers: ' . $e->getMessage());
        }
    }

    /**
     * Update grid data using proper refresh method
     *
     * @param array $orderIds
     * @return void
     */
    private function updateGridDataByOrderIds($orderIds)
    {
        if (empty($orderIds)) {
            return;
        }
        
        try {
            // Try to use the proper grid synchronizer if available
            $gridSynchronizer = $this->objectManager->get('Magento\Sales\Model\ResourceModel\GridPool');
            if (method_exists($gridSynchronizer, 'refreshByOrderIds')) {
                $gridSynchronizer->refreshByOrderIds($orderIds);
                $this->getLogger()->info('Refreshed grid using GridPool for order IDs');
                return;
            }
        } catch (\Exception $e) {
            $this->getLogger()->warning('Could not use GridPool: ' . $e->getMessage());
        }
        
        // Fallback to direct update if grid synchronizer is not available
        try {
            $connection = $this->getConnection();
            $orderTable = $this->getTableName('sales_order');
            $gridTable = $this->getTableName('sales_order_grid');
            
            if (!$connection->isTableExists($gridTable)) {
                $this->getLogger()->warning('Grid table does not exist');
                return;
            }
            
            // Get fresh order data
            $select = $connection->select()
                ->from(['o' => $orderTable], ['entity_id', 'status', 'state', 'updated_at'])
                ->where('entity_id IN (?)', $orderIds);
                
            $orderData = $connection->fetchAll($select);
            
            if (!empty($orderData)) {
                foreach ($orderData as $order) {
                    $connection->update(
                        $gridTable,
                        [
                            'status' => $order['status'],
                            'updated_at' => $order['updated_at']
                        ],
                        ['entity_id = ?' => $order['entity_id']]
                    );
                }
                $this->getLogger()->info(sprintf('Directly updated %d orders in grid table', count($orderData)));
            }
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to update grid: ' . $e->getMessage());
        }
    }
	/**
     * Force update of the grid - optimized to avoid unnecessary logs
     *
     * @param array $orderIds Optional array of order IDs to update
     * @return void
     */
    private function forceGridUpdate($orderIds = [])
    {
        try {
            // First, try to directly update the grid data for any specific orders
            if (!empty($orderIds)) {
                $this->updateGridDataByOrderIds($orderIds);
            }
            
            // Skip checking individual indexers by name to avoid log spam
            // Go straight to direct database update which is most reliable
            try {
                $connection = $this->getConnection();
                $indexerStateTable = $this->getTableName('indexer_state');
                
                if ($connection->isTableExists($indexerStateTable)) {
                    // Update all sales-related indexers at once
                    $connection->update(
                        $indexerStateTable,
                        ['status' => 'invalid', 'updated' => date('Y-m-d H:i:s')],
                        ['indexer_id LIKE ?' => '%sales%']
                    );
                    $this->getLogger()->info('Directly invalidated sales indexers in database');
                }
            } catch (\Exception $e) {
                // Only log error if database update fails
                $this->getLogger()->error('Failed to update indexer statuses: ' . $e->getMessage());
            }
            
            // Clean necessary caches to ensure UI updates
            $cacheTypes = ['collections', 'config', 'layout', 'full_page', 'block_html'];
            $cache = $this->objectManager->get('Magento\Framework\App\Cache\TypeListInterface');
            
            // Clean all caches at once without excessive logging
            try {
                foreach ($cacheTypes as $type) {
                    $cache->cleanType($type);
                }
                $this->getLogger()->info('Cleaned required caches to refresh UI');
            } catch (\Exception $e) {
                $this->getLogger()->error('Failed to clean caches: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->getLogger()->error('Failed to force grid update: ' . $e->getMessage());
        }
    }
	/**
     * Create invoice with inventory bypass
     *
     * @param \Magento\Sales\Model\Order $order
     * @return \Magento\Sales\Model\Order\Invoice|null
     */
    private function createInvoiceWithInventoryBypass($order)
    {
        $incrementId = $order->getIncrementId();
        $this->getLogger()->info('Creating invoice for order #' . $incrementId);
        
        // Use invoice service to create new invoice
        $invoiceService = $this->objectManager->create('Magento\Sales\Model\Service\InvoiceService');
        $invoice = $invoiceService->prepareInvoice($order);
        
        if (!$invoice || !$invoice->getTotalQty()) {
            throw new LocalizedException(__('Cannot create invoice without items for order #%1', $incrementId));
        }
        
        // Initialize invoice with bypass of inventory check
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->addComment(__('Invoice created via Mass Action'), false, false);
        $invoice->register();
        
        // Save invoice and order
        $transaction = $this->objectManager->create('Magento\Framework\DB\Transaction');
        $transaction->addObject($invoice)
                   ->addObject($order)
                   ->save();
        
        $this->getLogger()->info('Invoice #' . $invoice->getIncrementId() . ' created for order #' . $incrementId);
        
        return $invoice;
    }

    /**
     * Create shipment with inventory bypass
     *
     * @param \Magento\Sales\Model\Order $order
     * @return \Magento\Sales\Model\Order\Shipment|null
     */
    private function createShipmentWithInventoryBypass($order)
    {
        $incrementId = $order->getIncrementId();
        $this->getLogger()->info('Creating shipment for order #' . $incrementId);
        
        // Provide direct inventory handling
        $bypassInventoryCheck = true;
        
        // Use shipping service to create new shipment
        $orderConverter = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
        $shipment = $orderConverter->toShipment($order);
        
        // Add items to shipment
        $itemsShipped = false;
        foreach ($order->getAllItems() as $orderItem) {
            // Skip virtual items or parent items
            if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual() || $orderItem->getParentItemId()) {
                continue;
            }
            
            $qtyToShip = $orderItem->getQtyToShip();
            $shipmentItem = $orderConverter->itemToShipmentItem($orderItem);
            $shipmentItem->setQty($qtyToShip);
            
            // Add item to shipment
            $shipment->addItem($shipmentItem);
            $itemsShipped = true;
        }
        
        if (!$itemsShipped) {
            throw new LocalizedException(__('No items to ship for order #%1', $incrementId));
        }
        
        // Register shipment
        $shipment->addComment(__('Shipment created via Mass Action'), false, false);
        $shipment->register();
        
        // Save shipment and order
        $transaction = $this->objectManager->create('Magento\Framework\DB\Transaction');
        $transaction->addObject($shipment)
                   ->addObject($order)
                   ->save();
        
        $this->getLogger()->info('Shipment #' . $shipment->getIncrementId() . ' created for order #' . $incrementId);
        
        return $shipment;
    }
}