<?php

use Tailor\Classes\Blueprint;
use Tailor\Classes\BlueprintIndexer;

class BlueprintIndexerTest extends TestCase
{
    private $blueprintState = [];
    private $indexerCache;

    public function setUp(): void
    {
        parent::setUp();
        $this->indexerCache = BlueprintIndexer::$memoryCache;
        BlueprintIndexer::flushCache();
        $blueprint = new Blueprint;
        foreach (['defaultDatasource', 'resolvedPlugins', 'resolvedActiveTheme', 'resolvedThemes'] as $property) {
            $this->blueprintState[$property] = self::getProtectedProperty($blueprint, $property);
            if ($property !== 'defaultDatasource') {
                self::setProtectedProperty($blueprint, $property, []);
            }
        }
        Blueprint::setDefaultDatasource(base_path('modules/tailor/tests/fixtures/blueprints'));
    }

    public function tearDown(): void
    {
        $blueprint = new Blueprint;
        foreach ($this->blueprintState as $property => $value) {
            self::setProtectedProperty($blueprint, $property, $value);
        }
        BlueprintIndexer::$memoryCache = $this->indexerCache;
        parent::tearDown();
    }

    /**
     * testListingBlueprints
     */
    public function testListingBlueprints()
    {
        $sections = BlueprintIndexer::instance()->listSections();
        $this->assertCount(10, $sections);

        $mixins = BlueprintIndexer::instance()->listMixins();
        $this->assertCount(13, $mixins);

        $globals = BlueprintIndexer::instance()->listGlobals();
        $this->assertCount(1, $globals);
        $this->assertSame('Posts', BlueprintIndexer::instance()->find('edcd102e-0525-4e4d-b07e-633ae6c18db6')->name);
    }
}
