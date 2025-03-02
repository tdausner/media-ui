<?php
/** @noinspection PhpUnusedParameterInspection */
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

use Flowpack\Media\Ui\GraphQL\Context\AssetSourceContext;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\ProvidesOriginalUriInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\FileTypeIconService;
use t3n\GraphQL\ResolverInterface;

/**
 * @Flow\Scope("singleton")
 */
class AssetResolver implements ResolverInterface
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var FileTypeIconService
     */
    protected $fileTypeIconService;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @param AssetProxyInterface $assetProxy
     * @return string|null
     */
    public function id(AssetProxyInterface $assetProxy): ?string
    {
        return $assetProxy->getIdentifier();
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @return string|null
     */
    public function localId(AssetProxyInterface $assetProxy): ?string
    {
        return $assetProxy->getLocalAssetIdentifier();
    }

    /**
     * Returns the title of the associated local asset data or the label of the proxy as fallback
     *
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return string|null
     */
    public function label(AssetProxyInterface $assetProxy, array $variables, AssetSourceContext $assetSourceContext): ?string
    {
        $localAssetData = $assetSourceContext->getAssetForProxy($assetProxy);
        if ($localAssetData && $localAssetData->getTitle()) {
            return $localAssetData->getTitle();
        }
        return $assetProxy->getLabel();
    }

    /**
     * Returns true if the asset is at least used once
     *
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return bool|null
     */
    public function isInUse(AssetProxyInterface $assetProxy, array $variables, AssetSourceContext $assetSourceContext): ?bool
    {
        if (!$assetProxy->getLocalAssetIdentifier()) {
            return false;
        }
        return $this->assetService->isInUse($assetSourceContext->getAssetForProxy($assetProxy));
    }

    /**
     * Returns the caption of the associated local asset data
     *
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return string|null
     */
    public function caption(AssetProxyInterface $assetProxy, array $variables, AssetSourceContext $assetSourceContext): ?string
    {
        $localAssetData = $assetSourceContext->getAssetForProxy($assetProxy);
        return $localAssetData instanceof Asset ? $localAssetData->getCaption() : null;
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @return bool
     */
    public function imported(AssetProxyInterface $assetProxy): bool
    {
        // TODO: Find better way to make sure the asset originates from somewhere outside Neos
        return (bool)$assetProxy->getLocalAssetIdentifier() && $assetProxy->getAssetSource()->getIdentifier() !== 'neos';
    }

    /**
     *
     * Returns a matching icon uri for the given assetproxy
     *
     * @param AssetProxyInterface $assetProxy
     * @return array
     */
    public function file(AssetProxyInterface $assetProxy): array
    {
        $icon = $this->fileTypeIconService::getIcon($assetProxy->getFilename());

        if ($assetProxy instanceof ProvidesOriginalUriInterface) {
            $url = (string)$assetProxy->getOriginalUri();
        } else {
            $url = (string)$assetProxy->getPreviewUri();
        }

        return [
            'extension' => $icon['alt'],
            'mediaType' => $assetProxy->getMediaType(),
            'typeIcon' => [
                'width' => 16,
                'height' => 16,
                'url' => $this->resourceManager->getPublicPackageResourceUriByPath($icon['src']),
                'alt' => $icon['alt'],
            ],
            'size' => $assetProxy->getFileSize(),
            'url' => $url,
        ];
    }

    /**
     * Returns the iptc properties for assetproxies that implement the interface
     *
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @return string|null
     */
    public function iptcProperty(AssetProxyInterface $assetProxy, array $variables): ?string
    {
        $iptcProperties = $this->iptcProperties($assetProxy);
        return $iptcProperties[$variables['property']] ?? null;
    }

    /**
     * Returns the iptc properties for assetproxies that implement the interface
     *
     * @param AssetProxyInterface $assetProxy
     * @return array
     */
    public function iptcProperties(AssetProxyInterface $assetProxy): array
    {
        if ($assetProxy instanceof SupportsIptcMetadataInterface) {
            $properties = $assetProxy->getIptcProperties();
            return array_map(static function ($key) use ($properties) {
                return ['propertyName' => $key, 'value' => $properties[$key]];
            }, array_keys($properties));
        }
        return [];
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return string|null
     */
    public function copyrightNotice(AssetProxyInterface $assetProxy, array $variables, AssetSourceContext $assetSourceContext): ?string
    {
        $localAssetData = $assetSourceContext->getAssetForProxy($assetProxy);
        return $localAssetData instanceof Asset ? $localAssetData->getCopyrightNotice() : null;
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return string|null
     */
    public function lastModified(AssetProxyInterface $assetProxy, array $variables, AssetSourceContext $assetSourceContext): ?string
    {
        return $assetProxy->getLastModified() ? $assetProxy->getLastModified()->format(DATE_W3C) : null;
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return array<Tag>
     */
    public function tags(AssetProxyInterface $assetProxy, array $variables, AssetSourceContext $assetSourceContext): array
    {
        $localAssetData = $assetSourceContext->getAssetForProxy($assetProxy);
        return $localAssetData instanceof Asset ? $localAssetData->getTags()->toArray() : [];
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @param array $variables
     * @param AssetSourceContext $assetSourceContext
     * @return array<AssetCollection>
     */
    public function collections(AssetProxyInterface $assetProxy, array $variables, AssetSourceContext $assetSourceContext): array
    {
        $localAssetData = $assetSourceContext->getAssetForProxy($assetProxy);
        return $localAssetData instanceof Asset ? $localAssetData->getAssetCollections()->toArray() : [];
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @return int
     */
    public function width(AssetProxyInterface $assetProxy): int
    {
        return $assetProxy->getWidthInPixels() ?? 0;
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @return int
     */
    public function height(AssetProxyInterface $assetProxy): int
    {
        return $assetProxy->getHeightInPixels() ?? 0;
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @return string
     */
    public function thumbnailUrl(AssetProxyInterface $assetProxy): string
    {
        return (string)$assetProxy->getThumbnailUri();
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @return string
     */
    public function previewUrl(AssetProxyInterface $assetProxy): string
    {
        return (string)$assetProxy->getPreviewUri();
    }

    /**
     * @param AssetProxyInterface $assetProxy
     * @param int $maximumWidth
     * @param int $maximumHeight
     * @param string $ratioMode
     * @param bool $allowUpScaling
     * @param bool $allowCropping
     * @return array
     * @throws \Exception
     */
    public function thumbnail(
        AssetProxyInterface $assetProxy,
        int $maximumWidth,
        int $maximumHeight,
        string $ratioMode,
        bool $allowUpScaling,
        bool $allowCropping
    ): array {
        // TODO: Implement
        throw new \RuntimeException('Not implemented yet', 1590840085);
    }
}
