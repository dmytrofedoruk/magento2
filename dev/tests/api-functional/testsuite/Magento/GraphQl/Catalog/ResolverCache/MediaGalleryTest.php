<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Catalog\ResolverCache;

use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Controller\Adminhtml\Product\Save as AdminProductSaveController;
use Magento\Catalog\Model\Product\Gallery\GalleryManagement;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogGraphQl\Model\Resolver\Cache\Product\MediaGallery\ResolverCacheIdentity;
use Magento\CatalogGraphQl\Model\Resolver\Product\MediaGallery;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\App\Area as AppArea;
use Magento\Framework\App\ObjectManager\ConfigLoader;
use Magento\Framework\App\State as AppState;
use Magento\Framework\ObjectManagerInterface;
use Magento\GraphQlResolverCache\Model\Resolver\Result\CacheKey\Calculator\ProviderInterface;
use Magento\GraphQlResolverCache\Model\Resolver\Result\Type as GraphQlResolverCache;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\GraphQl\ResolverCacheAbstract;

/**
 * Test for \Magento\CatalogGraphQl\Model\Resolver\Product\MediaGallery resolver cache
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MediaGalleryTest extends ResolverCacheAbstract
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var GraphQlResolverCache
     */
    private $graphQlResolverCache;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->graphQlResolverCache = $this->objectManager->get(GraphQlResolverCache::class);
        $this->productRepository = $this->objectManager->get(ProductRepositoryInterface::class);

        parent::setUp();
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'product1', 'media_gallery_entries' => [[]]], as: 'product'),
    ]
    public function testSavingProductInAdminWithoutChangesDoesNotInvalidateResolverCache()
    {
        $product = $this->productRepository->get('product1');

        // Assert Media Gallery Resolver cache record does not exist before querying the product's media gallery
        $this->assertMediaGalleryResolverCacheRecordDoesNotExist($product);

        $query = $this->getProductWithMediaGalleryQuery($product);
        $response = $this->graphQlQuery($query);

        $this->assertNotEmpty($response['products']['items'][0]['media_gallery']);

        // Assert Media Gallery Resolver cache record exists after querying the product's media gallery
        $this->assertMediaGalleryResolverCacheRecordExists($product);

        // Save product in admin without changes
        $productManagement = $this->objectManager->create(ProductManagementInterface::class);

        // get count of products
        $originalProductCount = $productManagement->getCount();
        $this->assertGreaterThan(0, $originalProductCount);

        // emulate admin area
        Bootstrap::getInstance()->loadArea(AppArea::AREA_ADMINHTML);
        $this->objectManager->get(AppState::class)->setAreaCode(AppArea::AREA_ADMINHTML);
        $configLoader = $this->objectManager->get(ConfigLoader::class);
        $this->objectManager->configure($configLoader->load(AppArea::AREA_ADMINHTML));

        $context = $this->objectManager->create(\Magento\Backend\App\Action\Context::class);

        // overwrite $context's messageManager
        $messageManager = $this->getMockBuilder(\Magento\Framework\Message\ManagerInterface::class)
            ->enableProxyingToOriginalMethods()
            ->getMockForAbstractClass();

        $reflectionClass = new \ReflectionClass($context);
        $reflectionProperty = $reflectionClass->getProperty('messageManager');
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($context, $messageManager);

        /** @var AdminProductSaveController $adminProductSaveController */
        $adminProductSaveController = $this->objectManager->create(AdminProductSaveController::class, [
            'context' => $context,
        ]);

        $productData = $product->getData();

        // video_* keys are not passed to the controller when saving an image
        foreach ($productData['media_gallery']['images'] as &$image) {
            $image = array_filter($image, function ($key) {
                return strpos($key, 'video') === false;
            }, ARRAY_FILTER_USE_KEY);
        }

        unset($productData['entity_id']);

        $adminProductSaveController->getRequest()->setPostValue([
            'id' => $product->getId(),
            'product' => $productData,
            'set' => 4, // attribute set id
            'type' => 'simple',
            'store' => 1,
        ]);

        $messageManager->expects($this->never())->method('addErrorMessage');
        $messageManager->expects($this->never())->method('addExceptionMessage');
        $messageManager->expects($this->atLeastOnce())->method('addSuccessMessage');

        $adminProductSaveController->execute();

        // assert that product count is the same (i.e. product was not created, but "updated")
        $this->assertEquals(
            $originalProductCount,
            $productManagement->getCount(),
        );

        // Assert Media Gallery Resolver cache record exists after saving the product in admin without changes
        $this->assertMediaGalleryResolverCacheRecordExists($product);
    }

    /**
     * - product_simple_with_media_gallery_entries.php creates product with "simple" sku containing
     * link to 1 external YouTube video using an image placeholder in gallery
     *
     * - product_with_media_gallery.php creates product with "simple_product_with_media product" SKU
     * containing 1 image in gallery
     *
     * @magentoDbIsolation disabled
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple_with_media_gallery_entries.php
     * @magentoApiDataFixture Magento/Catalog/_files/product_with_media_gallery.php
     * @dataProvider actionMechanismProvider
     * @param callable $actionMechanismCallable
     * @param bool $isInvalidationAction
     */
    public function testMediaGalleryForProductVideos(callable $actionMechanismCallable, bool $isInvalidationAction)
    {
        // Test simple product with media
        $simpleProductWithMedia = $this->productRepository->get('simple_product_with_media');

        // Query the simple product with media
        $simpleProductWithMediaQuery = $this->getProductWithMediaGalleryQuery($simpleProductWithMedia);
        $simpleProductWithMediaQueryResponse = $this->graphQlQuery($simpleProductWithMediaQuery);
        $this->assertNotEmpty($simpleProductWithMediaQueryResponse['products']['items'][0]['media_gallery']);

        // Assert Media Gallery Resolver cache record exists after querying the product's media gallery
        $this->assertMediaGalleryResolverCacheRecordExists($simpleProductWithMedia);

        // Test simple product
        $product = $this->productRepository->get('simple');
        $this->assertMediaGalleryResolverCacheRecordDoesNotExist($product);
        $simpleProductQuery = $this->getProductWithMediaGalleryQuery($product);
        $response = $this->graphQlQuery($simpleProductQuery);
        $this->assertNotEmpty($response['products']['items'][0]['media_gallery']);
        $this->assertMediaGalleryResolverCacheRecordExists($product);

        // Query simple product the 2nd time
        $response2 = $this->graphQlQuery($simpleProductQuery);
        $this->assertEquals($response, $response2);

        // change product media gallery data
        $actionMechanismCallable($product);

        if ($isInvalidationAction) {
            // assert that cache entry for simple product query is invalidated
            $this->assertMediaGalleryResolverCacheRecordDoesNotExist($product);
        } else {
            // assert that cache entry for simple product query is not invalidated
            $this->assertMediaGalleryResolverCacheRecordExists($product);
        }

        // assert that cache entry for simple product with media query is not invalidated
        $this->assertMediaGalleryResolverCacheRecordExists($simpleProductWithMedia);

        // Query simple product the 3rd time
        $response3 = $this->graphQlQuery($simpleProductQuery);

        if ($isInvalidationAction) {
            // assert response is updated
            $this->assertNotEquals($response, $response3);
        } else {
            // assert response is the same
            $this->assertEquals($response, $response3);
        }
    }

    public function actionMechanismProvider(): array
    {
        // provider is invoked before setUp() is called so need to init here
        $objectManager = Bootstrap::getObjectManager();

        /** @var GalleryManagement $galleryManagement */
        $galleryManagement = $objectManager->get(GalleryManagement::class);

        /** @var ProductRepositoryInterface $productRepository */
        $productRepository = $objectManager->get(ProductRepositoryInterface::class);

        return [
            'add new media gallery entry' => [
                function (ProductInterface $product) use ($galleryManagement, $objectManager) {
                    /** @var ProductAttributeMediaGalleryEntryInterfaceFactory $mediaGalleryEntryFactory */
                    $mediaGalleryEntryFactory = $objectManager->get(
                        ProductAttributeMediaGalleryEntryInterfaceFactory::class
                    );

                    /** @var ImageContentInterfaceFactory $imageContentFactory */
                    $imageContentFactory = $objectManager->get(ImageContentInterfaceFactory::class);
                    $imageContent = $imageContentFactory->create();
                    $imageContent->setBase64EncodedData(
                        // black 1x1 image
                        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
                    );
                    $imageContent->setType("image/png");
                    $imageContent->setName("new_image.png");

                    $newImage = $mediaGalleryEntryFactory->create();
                    $newImage->setDisabled(false);
                    $newImage->setFile('/n/e/new_image.png');
                    $newImage->setLabel('New Image Alt Text');
                    $newImage->setMediaType('image');
                    $newImage->setPosition(2);
                    $newImage->setContent($imageContent);

                    $galleryManagement->create($product->getSku(), $newImage);
                },
                true
            ],
            'update media label' => [
                function (ProductInterface $product) use ($galleryManagement) {
                    $mediaEntry = $product->getMediaGalleryEntries()[0];
                    $mediaEntry->setLabel('new_' . $mediaEntry->getLabel());
                    $galleryManagement->update($product->getSku(), $mediaEntry);
                },
                true
            ],
            'update video description' => [
                function (ProductInterface $product) use ($galleryManagement) {
                    $mediaEntry = $product->getMediaGalleryEntries()[0];
                    $mediaEntry
                        ->getExtensionAttributes()
                        ->getVideoContent()
                        ->setVideoDescription('Something different');

                    $galleryManagement->update($product->getSku(), $mediaEntry);
                },
                true
            ],
            'update product name' => [
                function (ProductInterface $product) use ($productRepository) {
                    $product->setName('new name');
                    $productRepository->save($product);
                },
                false
            ],
            'remove media' => [
                function (ProductInterface $product) use ($galleryManagement) {
                    $mediaEntry = $product->getMediaGalleryEntries()[0];
                    $galleryManagement->remove($product->getSku(), $mediaEntry->getId());
                },
                true
            ],
            'save product without change' => [
                function (ProductInterface $product) use ($productRepository) {
                    $productRepository->save($product);
                },
                false
            ],
        ];
    }

    /**
     * Assert that media gallery resolver cache tags and cache key vary when querying a different product.
     *
     * @magentoDbIsolation disabled
     * @magentoApiDataFixture Magento/Catalog/_files/product_simple_with_media_gallery_entries.php
     * @magentoApiDataFixture Magento/Catalog/_files/product_with_media_gallery.php
     */
    public function testMediaGalleryResolverCacheKeyAndTags()
    {
        // Test simple product
        $simpleProduct = $this->productRepository->get('simple');
        // Query the simple product
        $simpleProductQuery = $this->getProductWithMediaGalleryQuery($simpleProduct);
        $simpleProductQueryResponse = $this->graphQlQuery($simpleProductQuery);
        // Query the simple product again
        $simpleProductQueryResponse2 = $this->graphQlQuery($simpleProductQuery);
        $this->assertEquals($simpleProductQueryResponse, $simpleProductQueryResponse2);

        $simpleProductCacheKey = $this->getCacheKeyForMediaGalleryResolver($simpleProduct);
        $simpleProductCacheTags = $this->getCacheTagsUsedInMediaGalleryResolverCache($simpleProductCacheKey);

        // Verify cache tags are generated correctly for the simple product
        $this->assertEquals(
            $this->getExpectedCacheTags($simpleProduct),
            $simpleProductCacheTags
        );

        // Test simple product with media
        $simpleProductWithMedia = $this->productRepository->get('simple_product_with_media');

        // Query the simple product with media
        $simpleProductWithMediaQuery = $this->getProductWithMediaGalleryQuery($simpleProductWithMedia);
        $simpleProductWithMediaQueryResponse =$this->graphQlQuery($simpleProductWithMediaQuery);
        $this->assertNotEquals($simpleProductQueryResponse, $simpleProductWithMediaQueryResponse);

        $simpleProductWithMediaCacheKey = $this->getCacheKeyForMediaGalleryResolver($simpleProductWithMedia);
        $simpleProductWithMediaCacheTags = $this->getCacheTagsUsedInMediaGalleryResolverCache(
            $simpleProductWithMediaCacheKey
        );
        // Verify cache tags are generated correctly for the simple product with media
        $this->assertEquals(
            $this->getExpectedCacheTags($simpleProductWithMedia),
            $simpleProductWithMediaCacheTags
        );

        // Verify different product query has different cache key
        $this->assertNotEquals($simpleProductCacheKey, $simpleProductWithMediaCacheKey);

        // Verify different product query has different cache tags
        $this->assertNotEquals($simpleProductCacheTags, $simpleProductWithMediaCacheTags);
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'product1', 'media_gallery_entries' => [[]]], as: 'product'),
    ]
    public function testThatThereAreNoOrphanedCacheIdsInTagFileAfterInvalidation()
    {
        $product = $this->productRepository->get('product1');
        $this->graphQlQuery($this->getProductWithMediaGalleryQuery($product));
        $this->assertMediaGalleryResolverCacheRecordExists($product);

        // update media gallery-related field and assert cache is invalidated
        $this->actionMechanismProvider()['update media label'][0]($product);
        $this->assertMediaGalleryResolverCacheRecordDoesNotExist($product);

        $this->assertCacheIdIsNotOrphanedInTagsForProduct($product);
    }

    /**
     * @magentoApiDataFixture Magento/Catalog/_files/product_with_media_gallery.php
     * @return void
     */
    public function testCacheIsInvalidatedOnProductDeletion()
    {
        $product = $this->productRepository->get('simple_product_with_media');
        $query = $this->getProductWithMediaGalleryQuery($product);
        $this->graphQlQuery($query);
        $this->assertMediaGalleryResolverCacheRecordExists($product);

        $registry = $this->objectManager->get(\Magento\Framework\Registry::class);
        /** @var ProductRepositoryInterface $productRepository */
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);

        $this->productRepository->delete($product);

        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', false);

        // assert cache is invalidated
        $this->assertMediaGalleryResolverCacheRecordDoesNotExist($product);
    }

    /**
     * Assert that cache id is not present in any of the cache tag files for the $product.
     *
     * @param ProductInterface $product
     * @return void
     * @throws \Zend_Cache_Exception
     */
    private function assertCacheIdIsNotOrphanedInTagsForProduct(ProductInterface $product)
    {
        $cacheKey = $this->getCacheKeyForMediaGalleryResolver($product);
        $cacheLowLevelFrontend = $this->graphQlResolverCache->getLowLevelFrontend();
        $cacheIdPrefix = $cacheLowLevelFrontend->getOption('cache_id_prefix');
        $cacheBackend = $cacheLowLevelFrontend->getBackend();

        $this->assertNotContains(
            $cacheIdPrefix . $cacheKey,
            $cacheBackend->getIdsMatchingTags([
                $cacheIdPrefix . 'GRAPHQL_QUERY_RESOLVER_RESULT'
            ]),
            'Cache id is still present in GRAPHQL_QUERY_RESOLVER_RESULT tag file after invalidation'
        );

        $this->assertNotContains(
            $cacheIdPrefix . $cacheKey,
            $cacheBackend->getIdsMatchingTags([
                $cacheIdPrefix . 'GQL_MEDIA_GALLERY_' . strtoupper($product->getSku()),
            ]),
            sprintf(
                'Cache id is still present in GQL_MEDIA_GALLERY_%s tag file after invalidation',
                strtoupper($product->getSku())
            )
        );
    }

    /**
     * Assert that media gallery cache record exists for the $product.
     *
     * @param ProductInterface $product
     * @return void
     */
    private function assertMediaGalleryResolverCacheRecordExists(ProductInterface $product)
    {
        $cacheKey = $this->getCacheKeyForMediaGalleryResolver($product);
        $cacheEntry = $this->graphQlResolverCache->load($cacheKey);

        $this->assertNotFalse(
            $cacheEntry,
            sprintf('Media gallery cache entry for product with sku "%s" does not exist', $product->getSku())
        );

        $cacheEntryDecoded = json_decode($cacheEntry, true);

        $this->assertEquals(
            $product->getMediaGalleryEntries()[0]->getLabel(),
            $cacheEntryDecoded[0]['label']
        );
    }

    /**
     * Assert that media gallery cache record does not exist for the $product.
     *
     * @param ProductInterface $product
     * @return void
     */
    private function assertMediaGalleryResolverCacheRecordDoesNotExist(ProductInterface $product)
    {
        $cacheKey = $this->getCacheKeyForMediaGalleryResolver($product);
        $this->assertFalse(
            $this->graphQlResolverCache->test($cacheKey),
            sprintf('Media gallery cache entry for product with sku "%s" exists', $product->getSku())
        );
    }

    /**
     * Get the expected media gallery cache tags based on the $product.
     *
     * @param ProductInterface $product
     * @return array
     */
    private function getExpectedCacheTags(ProductInterface $product): array
    {
        $cacheIdPrefix = $this->graphQlResolverCache->getLowLevelFrontend()->getOption('cache_id_prefix');

        return [
            $cacheIdPrefix . strtoupper(ResolverCacheIdentity::CACHE_TAG . '_' . $product->getSku()),
            $cacheIdPrefix . strtoupper(GraphQlResolverCache::CACHE_TAG),
            $cacheIdPrefix . 'MAGE',
        ];
    }

    /**
     * Get the actual cache tags used in media gallery resolver cache.
     *
     * @param string $cacheKey
     * @return array
     */
    private function getCacheTagsUsedInMediaGalleryResolverCache(string $cacheKey): array
    {
        $metadatas = $this->graphQlResolverCache->getLowLevelFrontend()->getMetadatas($cacheKey);
        return $metadatas['tags'];
    }

    /**
     * Get cache key for media gallery resolver based on the $product
     *
     * @param ProductInterface $product
     * @return string
     */
    private function getCacheKeyForMediaGalleryResolver(ProductInterface $product): string
    {
        $resolverMock = $this->getMockBuilder(MediaGallery::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var ProviderInterface $cacheKeyCalculatorProvider */
        $cacheKeyCalculatorProvider = $this->objectManager->get(ProviderInterface::class);

        $cacheKeyFactor = $cacheKeyCalculatorProvider
            ->getKeyCalculatorForResolver($resolverMock)
            ->calculateCacheKey(['model' => $product]);

        $cacheKeyQueryPayloadMetadata = MediaGallery::class . '\Interceptor[]';

        $cacheKeyParts = [
            GraphQlResolverCache::CACHE_TAG,
            $cacheKeyFactor,
            sha1($cacheKeyQueryPayloadMetadata)
        ];

        // strtoupper is called in \Magento\Framework\Cache\Frontend\Adapter\Zend::_unifyId
        return strtoupper(implode('_', $cacheKeyParts));
    }

    /**
     * Compile GraphQL query for getting product data with media gallery
     *
     * @param ProductInterface $product
     * @return string
     */
    private function getProductWithMediaGalleryQuery(ProductInterface $product): string
    {
        return <<<QUERY
{
  products(filter: {sku: {eq: "{$product->getSku()}"}}) {
    items {
      small_image {
        url
      }
      media_gallery {
      	label
        url
        position
        disabled
        ... on ProductVideo {
              video_content {
                  media_type
                  video_provider
                  video_url
                  video_title
                  video_description
                  video_metadata
              }
          }
      }
    }
  }
}
QUERY;
    }
}
