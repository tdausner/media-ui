<?php

declare(strict_types=1);

namespace Flowpack\Media\Ui\GraphQL\Resolver\Type;

/*
 * This file is part of the Flowpack.Media.Ui package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flowpack\Media\Ui\Exception as MediaUiException;
use Flowpack\Media\Ui\Domain\ImageMapper;
use Flowpack\Media\Ui\Domain\Model\AssetProxyIteratorAggregate;
use Flowpack\Media\Ui\Domain\Model\SearchTerm;
use Flowpack\Media\Ui\GraphQL\Context\AssetSourceContext;
use Flowpack\Media\Ui\Infrastructure\Neos\Media\AssetProxyListIterator;
use Flowpack\Media\Ui\Infrastructure\Neos\Media\AssetProxyQueryIterator;
use Flowpack\Media\Ui\Service\AssetChangeLog;
use Flowpack\Media\Ui\Service\SimilarityService;
use Flowpack\Media\Ui\Service\UsageDetailsService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\AssetSource\AssetTypeFilter;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetProxy;
use Neos\Media\Domain\Model\AssetSource\Neos\NeosAssetSource;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsTaggingInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Model\VariantSupportInterface;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Utility\Exception\FilesException;
use Neos\Utility\Files;
use Psr\Log\LoggerInterface;
use t3n\GraphQL\ResolverInterface;

/**
 * @Flow\Scope("singleton")
 */
class QueryResolver implements ResolverInterface
{

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var UsageDetailsService
     */
    protected $assetUsageService;

    /**
     * @Flow\Inject
     * @var AssetChangeLog
     */
    protected $assetChangeLog;

    /**
     * @Flow\Inject
     * @var SimilarityService
     */
    protected $similarityService;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @Flow\InjectConfiguration(package="Flowpack.Media.Ui")
     * @var array
     */
    protected $settings;

    /**
     * Returns total count of asset proxies in the given asset source
     *
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return int
     * @noinspection PhpUnusedParameterInspection
     */
    public function assetCount($_, array $variables, AssetSourceContext $assetSourceContext): int
    {
        $iterator = $this->createAssetProxyIterator($variables, $assetSourceContext);

        if (!$iterator) {
            $this->systemLogger->error('Could not build asset query for given variables', $variables);
            return 0;
        }

        return count($iterator);
    }

    /**
     * Helper to create a asset proxy query for other methods
     *
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return AssetProxyIteratorAggregate|null
     */
    protected function createAssetProxyIterator(
        array $variables,
        AssetSourceContext $assetSourceContext
    ): ?AssetProxyIteratorAggregate {
        [
            'assetSourceId' => $assetSourceId,
            'tagId' => $tagId,
            'assetCollectionId' => $assetCollectionId,
            'mediaType' => $mediaType,
            'searchTerm' => $searchTerm,
            'sortBy' => $sortBy,
            'sortDirection' => $sortDirection
        ] = $variables + [
            'assetSourceId' => 'neos',
            'tagId' => null,
            'assetCollectionId' => null,
            'mediaType' => null,
            'searchTerm' => null,
            'sortBy' => null,
            'sortDirection' => null,
        ];

        $activeAssetSource = $assetSourceContext->getAssetSource($assetSourceId);
        if (!$activeAssetSource) {
            return null;
        }
        $assetProxyRepository = $activeAssetSource->getAssetProxyRepository();

        if (is_string($mediaType) && !empty($mediaType)) {
            try {
                $assetTypeFilter = new AssetTypeFilter(ucfirst($mediaType));
                $assetProxyRepository->filterByType($assetTypeFilter);
            } catch (\InvalidArgumentException $e) {
                $this->systemLogger->warning('Ignoring invalid mediatype when filtering assets ' . $mediaType);
            }
        }

        if ($assetCollectionId && $assetProxyRepository instanceof SupportsCollectionsInterface) {
            /** @var AssetCollection $assetCollection */
            $assetCollection = $this->assetCollectionRepository->findByIdentifier($assetCollectionId);
            if ($assetCollection) {
                $assetProxyRepository->filterByCollection($assetCollection);
            }
        }

        if ($sortBy && $assetProxyRepository instanceof SupportsSortingInterface) {
            switch ($sortBy) {
                case 'name':
                    $assetProxyRepository->orderBy(['resource.filename' => $sortDirection]);
                    break;
                case 'lastModified':
                default:
                    $assetProxyRepository->orderBy(['lastModified' => $sortDirection]);
                    break;
            }
        }

        if ($tagId && $assetProxyRepository instanceof SupportsTaggingInterface) {
            /** @var Tag $tag */
            $tag = $this->tagRepository->findByIdentifier($tagId);
            if ($tag) {
                return AssetProxyQueryIterator::from(
                    $assetProxyRepository->findByTag($tag)->getQuery()
                );
            }
        }

        if ($searchTerm = SearchTerm::from($searchTerm)) {
            if ($identifier = $searchTerm->getAssetIdentifierIfPresent()) {
                return AssetProxyListIterator::of(
                    $assetProxyRepository->getAssetProxy($identifier)
                );
            } else {
                return AssetProxyQueryIterator::from(
                    $assetProxyRepository->findBySearchTerm((string) $searchTerm)->getQuery()
                );
            }
        }

        return AssetProxyQueryIterator::from(
            $assetProxyRepository->findAll()->getQuery()
        );
    }

    /**
     * Returns a list of accessible and inaccessible relations for the given asset
     *
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return array
     */
    public function assetUsageDetails($_, array $variables, AssetSourceContext $assetSourceContext): array
    {
        [
            'id' => $id,
            'assetSourceId' => $assetSourceId,
        ] = $variables + ['id' => null, 'assetSourceId' => null];

        $assetProxy = $assetSourceContext->getAssetProxy($id, $assetSourceId);

        if (!$assetProxy || !$assetProxy->getLocalAssetIdentifier()) {
            return [];
        }

        $asset = $assetSourceContext->getAssetForProxy($assetProxy);

        if (!$asset) {
            return [];
        }

        return $this->assetUsageService->resolveUsagesForAsset($asset);
    }

    /**
     * Returns the total usage count for the given asset
     *
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return int
     */
    public function assetUsageCount($_, array $variables, AssetSourceContext $assetSourceContext): int
    {
        [
            'id' => $id,
            'assetSourceId' => $assetSourceId,
        ] = $variables + ['id' => null, 'assetSourceId' => null];

        $assetProxy = $assetSourceContext->getAssetProxy($id, $assetSourceId);

        if (!$assetProxy || !$assetProxy->getLocalAssetIdentifier()) {
            return 0;
        }

        $asset = $assetSourceContext->getAssetForProxy($assetProxy);

        if (!$asset) {
            return 0;
        }

        return $this->assetService->getUsageCount($asset);
    }

    /**
     * Returns an array with helpful configurations for interacting with the API
     *
     * @param $_
     * @return array
     */
    public function config($_): array
    {
        return [
            'uploadMaxFileSize' => $this->getMaximumFileUploadSize(),
            'uploadMaxFileUploadLimit' => $this->getMaximumFileUploadLimit(),
            'currentServerTime' => (new \DateTime())->format(DATE_W3C),
        ];
    }

    /**
     * Returns the lowest configured maximum upload file size
     *
     * @return int
     */
    protected function getMaximumFileUploadSize(): int
    {
        try {
            return (int)min(
                Files::sizeStringToBytes(ini_get('post_max_size')),
                Files::sizeStringToBytes(ini_get('upload_max_filesize'))
            );
        } catch (FilesException $e) {
            return 0;
        }
    }

    /**
     * Returns the maximum number of files that can be uploaded
     *
     * @return int
     */
    protected function getMaximumFileUploadLimit(): int
    {
        return (int)($this->settings['maximumFileUploadLimit'] ?? 10);
    }

    /**
     * Provides a filterable list of asset proxies. These are the main entities for media management.
     *
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return AssetProxyIteratorAggregate|null
     */
    public function assets(
        $_,
        array $variables,
        AssetSourceContext $assetSourceContext
    ): ?AssetProxyIteratorAggregate {
        ['limit' => $limit, 'offset' => $offset] = $variables + ['limit' => 20, 'offset' => 0];
        $iterator = $this->createAssetProxyIterator($variables, $assetSourceContext);

        if (!$iterator) {
            $this->systemLogger->error('Could not build assets query for given variables', $variables);
            return null;
        }

        $iterator->setOffset($offset);
        $iterator->setLimit($limit);

        return $iterator;
    }

    /**
     * Provides a list of all unused assets in local asset source
     *
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return array<AssetProxyInterface>
     * @throws MediaUiException
     */
    public function unusedAssets($_, array $variables, AssetSourceContext $assetSourceContext): array
    {
        ['limit' => $limit, 'offset' => $offset] = $variables + ['limit' => 20, 'offset' => 0];

        /** @var NeosAssetSource $neosAssetSource */
        $neosAssetSource = $assetSourceContext->getAssetSource('neos');

        return array_map(static function ($asset) use ($neosAssetSource) {
            return new NeosAssetProxy($asset, $neosAssetSource);
        }, $this->assetUsageService->getUnusedAssets($limit, $offset));
    }

    /**
     * Provides number of unused assets in local asset source
     *
     * @return int
     * @throws MediaUiException
     */
    public function unusedAssetCount(): int
    {
        return $this->assetUsageService->getUnusedAssetCount();
    }

    /**
     * Provides a list of all tags
     *
     * @param $_
     * @param array $variables
     * @return array<Tag>
     */
    public function tags($_, array $variables): array
    {
        return $this->tagRepository->findAll()->toArray();
    }

    /**
     * @param $_
     * @param array $variables
     * @return Tag|null
     */
    public function tag($_, array $variables): ?Tag
    {
        $id = $variables['id'] ?? null;
        /** @var Tag $tag */
        $tag = $id ? $this->tagRepository->findByIdentifier($id) : null;
        return $tag;
    }

    /**
     * Returns the list of all registered asset sources. By default the asset source `neos` should always exist.
     *
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return array<AssetSourceInterface>
     */
    public function assetSources($_, array $variables, AssetSourceContext $assetSourceContext): array
    {
        return $assetSourceContext->getAssetSources();
    }

    /**
     * @param $_
     * @param array $variables
     * @return array<AssetCollection>
     */
    public function assetCollections($_, array $variables): array
    {
        return $this->assetCollectionRepository->findAll()->toArray();
    }

    /**
     * @param $_
     * @param array $variables
     * @return AssetCollection|null
     */
    public function assetCollection($_, array $variables): ?AssetCollection
    {
        $id = $variables['id'] ?? null;
        /** @var AssetCollection $assetCollection */
        $assetCollection = $id ? $this->assetCollectionRepository->findByIdentifier($id) : null;
        return $assetCollection;
    }

    /**
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return AssetProxyInterface|null
     */
    public function asset($_, array $variables, AssetSourceContext $assetSourceContext): ?AssetProxyInterface
    {
        [
            'id' => $id,
            'assetSourceId' => $assetSourceId,
        ] = $variables + ['id' => null, 'assetSourceId' => null];

        return $assetSourceContext->getAssetProxy($id, $assetSourceId);
    }

    /**
     * @param $_
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return AssetVariantInterface[]
     */
    public function assetVariants($_, array $variables, AssetSourceContext $assetSourceContext): array
    {
        $assetProxy = $this->asset($_, $variables, $assetSourceContext);
        if ($assetProxy === null || !($assetProxy instanceof NeosAssetProxy) || !($assetProxy->getAsset() instanceof VariantSupportInterface)) {
            return [];
        }
        $asset = $this->persistenceManager->getObjectByIdentifier($assetProxy->getLocalAssetIdentifier(), Asset::class);

        /** @var VariantSupportInterface $originalAsset */
        $originalAsset = ($asset instanceof AssetVariantInterface ? $asset->getOriginalAsset() : $asset);

        return $originalAsset->getVariants();
    }

    /**
     * @param $_
     * @param array $variables
     * @return array
     */
    public function changedAssets($_, array $variables): array
    {
        /** @var string $since */
        $since = $variables['since'] ?? null;
        $changes = $this->assetChangeLog->getChanges();

        $filteredChanges = [];
        $lastModified = null;
        foreach ($changes as $change) {
            if ($since !== null && $change['lastModified'] <= $since) {
                continue;
            }
            if ($lastModified === null || $change['lastModified'] > $lastModified) {
                $lastModified = $change['lastModified'];
            }
            $filteredChanges[] = $change;
        }

        return [
            'lastModified' => $lastModified,
            'changes' => $filteredChanges,
        ];
    }

    public function similarAssets($_, array $variables, AssetSourceContext $assetSourceContext): array
    {
        [
            'id' => $id,
            'assetSourceId' => $assetSourceId,
        ] = $variables + ['id' => null, 'assetSourceId' => null];

        $assetProxy = $assetSourceContext->getAssetProxy($id, $assetSourceId);

        if (!$assetProxy) {
            return [];
        }

        $asset = $assetSourceContext->getAssetForProxy($assetProxy);

        if (!$asset) {
            return [];
        }

        $similarAssets = $this->similarityService->getSimilarAssets($asset);
        return array_map(function ($asset) use ($assetSourceContext) {
            $assetId = $this->persistenceManager->getIdentifierByObject($asset);
            return $assetSourceContext->getAssetProxy($assetId, $asset->getAssetSourceIdentifier());
        }, $similarAssets);
    }
}
