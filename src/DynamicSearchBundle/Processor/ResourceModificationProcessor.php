<?php

namespace DynamicSearchBundle\Processor;

use DynamicSearchBundle\Builder\ContextDefinitionBuilderInterface;
use DynamicSearchBundle\Context\ContextDefinitionInterface;
use DynamicSearchBundle\Document\IndexDocument;
use DynamicSearchBundle\Exception\RuntimeException;
use DynamicSearchBundle\Exception\SilentException;
use DynamicSearchBundle\Generator\IndexDocumentGeneratorInterface;
use DynamicSearchBundle\Logger\LoggerInterface;
use DynamicSearchBundle\Manager\IndexManagerInterface;
use DynamicSearchBundle\Normalizer\Resource\NormalizedDataResourceInterface;
use DynamicSearchBundle\Normalizer\Resource\ResourceMetaInterface;
use DynamicSearchBundle\Processor\Harmonizer\ResourceHarmonizerInterface;
use DynamicSearchBundle\Provider\IndexProviderInterface;
use DynamicSearchBundle\Provider\PreConfiguredIndexProviderInterface;
use DynamicSearchBundle\Registry\ContextGuardRegistryInterface;
use DynamicSearchBundle\Resource\Container\ResourceContainerInterface;

class ResourceModificationProcessor implements ResourceModificationProcessorInterface
{
    protected LoggerInterface $logger;
    protected ContextDefinitionBuilderInterface $contextDefinitionBuilder;
    protected IndexDocumentGeneratorInterface $indexDocumentGenerator;
    protected IndexManagerInterface $indexManager;
    protected ResourceHarmonizerInterface $resourceHarmonizer;
    protected ContextGuardRegistryInterface $contextGuardRegistry;

    public function __construct(
        LoggerInterface $logger,
        ContextDefinitionBuilderInterface $contextDefinitionBuilder,
        IndexDocumentGeneratorInterface $indexDocumentGenerator,
        IndexManagerInterface $indexManager,
        ResourceHarmonizerInterface $resourceHarmonizer,
        ContextGuardRegistryInterface $contextGuardRegistry
    ) {
        $this->logger = $logger;
        $this->contextDefinitionBuilder = $contextDefinitionBuilder;
        $this->indexDocumentGenerator = $indexDocumentGenerator;
        $this->indexManager = $indexManager;
        $this->resourceHarmonizer = $resourceHarmonizer;
        $this->contextGuardRegistry = $contextGuardRegistry;
    }

    public function process(ContextDefinitionInterface $contextDefinition, $resource): void
    {
        $indexProvider = $this->getIndexProvider($contextDefinition);

        $normalizedResourceStack = $this->resourceHarmonizer->harmonizeUntilNormalizedResourceStack($contextDefinition, $resource);
        if ($normalizedResourceStack === null) {
            // nothing to log: done by harmonizer.
            return;
        }

        foreach ($normalizedResourceStack as $normalizedResource) {
            if (!$normalizedResource instanceof NormalizedDataResourceInterface) {
                $this->logger->error(
                    sprintf('Normalized resource needs to be instance of %s. Skipping...', NormalizedDataResourceInterface::class),
                    $contextDefinition->getDataProviderName(),
                    $contextDefinition->getName()
                );

                continue;
            }

            $resourceMeta = $normalizedResource->getResourceMeta();
            if (empty($resourceMeta->getDocumentId())) {
                $this->logger->error(
                    'Unable to generate index document: No document id given. Skipping...',
                    $contextDefinition->getDataProviderName(),
                    $contextDefinition->getName()
                );

                continue;
            }

            $approvedByContextGuard = $this->invokeContextGuard($contextDefinition->getName(), $resourceMeta);
            if ($approvedByContextGuard === false) {
                $this->logger->debug(
                    'Resource has been rejected by context guard. Skipping...',
                    $contextDefinition->getDataProviderName(),
                    $contextDefinition->getName()
                );

                continue;
            }

            $resourceContainer = $normalizedResource->getResourceContainer();

            try {
                $indexDocument = $this->indexDocumentGenerator->generate($contextDefinition, $resourceMeta, $resourceContainer, [
                    'preConfiguredIndexProvider' => $indexProvider instanceof PreConfiguredIndexProviderInterface
                ]);
            } catch (SilentException $e) {
                $this->logger->debug($e->getMessage(), $contextDefinition->getDataProviderName(), $contextDefinition->getName());
                continue;
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage(), $contextDefinition->getDataProviderName(), $contextDefinition->getName());
                continue;
            }

            $this->validateAndSubmitIndexDocument($contextDefinition, $indexProvider, $indexDocument, $resourceContainer->getResourceScaffolderIdentifier());
        }
    }

    public function processByResourceMeta(ContextDefinitionInterface $contextDefinition, ResourceMetaInterface $resourceMeta, $resource): void
    {
        $indexProvider = $this->getIndexProvider($contextDefinition);

        $resourceContainer = $this->resourceHarmonizer->harmonizeUntilResourceContainer($contextDefinition, $resource);
        if (!$resourceContainer instanceof ResourceContainerInterface) {
            // nothing to log: done by harmonizer
            return;
        }

        if (empty($resourceMeta->getDocumentId())) {
            $this->logger->error(
                'Unable to generate index document: No document id given. Skipping...',
                $contextDefinition->getDataProviderName(),
                $contextDefinition->getName()
            );

            return;
        }

        $approvedByContextGuard = $this->invokeContextGuard($contextDefinition->getName(), $resourceMeta);
        if ($approvedByContextGuard === false) {
            $this->logger->debug(
                'Resource has been rejected by context guard. Skipping...',
                $contextDefinition->getDataProviderName(),
                $contextDefinition->getName()
            );

            return;
        }

        try {
            $indexDocument = $this->indexDocumentGenerator->generate($contextDefinition, $resourceMeta, $resourceContainer, [
                'preConfiguredIndexProvider' => $indexProvider instanceof PreConfiguredIndexProviderInterface
            ]);
        } catch (SilentException $e) {
            $this->logger->debug($e->getMessage(), $contextDefinition->getDataProviderName(), $contextDefinition->getName());
            return;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage(), $contextDefinition->getDataProviderName(), $contextDefinition->getName());
            return;
        }

        $this->validateAndSubmitIndexDocument($contextDefinition, $indexProvider, $indexDocument, $resourceContainer->getResourceScaffolderIdentifier());
    }

    protected function validateAndSubmitIndexDocument(
        ContextDefinitionInterface $contextDefinition,
        IndexProviderInterface $indexProvider,
        IndexDocument $indexDocument,
        string $resourceScaffolderName
    ): void {

        $logType = 'debug';
        $contextDispatchType = $contextDefinition->getContextDispatchType();

        if (count($indexDocument->getIndexFields()) === 0) {
            if ($contextDefinition->getContextDispatchType() === ContextDefinitionInterface::CONTEXT_DISPATCH_TYPE_UPDATE) {
                $contextDispatchType = ContextDefinitionInterface::CONTEXT_DISPATCH_TYPE_DELETE;
                $logMessage = sprintf('Index Document "%s" does not have any index fields. Trying to remove it from index...', $indexDocument->getDocumentId());
            } else {
                $logType = 'error';
                $contextDispatchType = null;
                $logMessage = sprintf('Index Document "%s" does not have any index fields. Skip Indexing...', $indexDocument->getDocumentId());
            }
        } else {
            $logA = sprintf('Index Document with %d fields successfully generated', count($indexDocument->getIndexFields()));
            $logB = sprintf('Used "%s" as resource scaffolder', $resourceScaffolderName);
            $logC = sprintf('Used "%s" as data normalizer', $contextDefinition->getResourceNormalizerName());
            $logMessage = sprintf('%s. %s. %s.', $logA, $logB, $logC);
        }

        $this->logger->log($logType, $logMessage, $contextDefinition->getDataProviderName(), $contextDefinition->getName());

        if ($contextDispatchType === null) {
            return;
        }

        // switch dispatch type!
        if ($contextDefinition->getContextDispatchType() !== $contextDispatchType) {
            $contextDefinition = $this->contextDefinitionBuilder->buildContextDefinition($contextDefinition->getName(), $contextDispatchType);
        }

        $this->sendIndexDocumentToIndexProvider($contextDefinition, $indexProvider, $indexDocument);
    }

    protected function sendIndexDocumentToIndexProvider(
        ContextDefinitionInterface $contextDefinition,
        IndexProviderInterface $indexProvider,
        IndexDocument $indexDocument
    ): void {
        try {
            $indexProvider->processDocument($contextDefinition, $indexDocument);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf(
                'Error while executing processing index document (%s) via provider. Error was: "%s".',
                $contextDefinition->getContextDispatchType(),
                $e->getMessage()
            ));
        }
    }

    protected function invokeContextGuard(string $contextName, ResourceMetaInterface $resourceMeta): bool
    {
        foreach ($this->contextGuardRegistry->getAllGuards() as $contextGuard) {
            if ($contextGuard->verifyResourceMetaForContext($contextName, $resourceMeta) === false) {
                return false;
            }
        }

        return true;
    }

    protected function getIndexProvider(ContextDefinitionInterface $contextDefinition): IndexProviderInterface
    {
        try {
            $indexProvider = $this->indexManager->getIndexProvider($contextDefinition);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf(
                    'Unable to load index provider "%s". Error was: %s',
                    $contextDefinition->getIndexProviderName(),
                    $e->getMessage()
                )
            );
        }

        return $indexProvider;
    }
}
