<?php

use Tailor\Classes\Blueprint;
use Tailor\Classes\Blueprint\EntryBlueprint;
use Tailor\Classes\Blueprint\GlobalBlueprint;
use Tailor\Classes\Blueprint\MixinBlueprint;
use Tailor\Classes\Blueprint\SingleBlueprint;
use Tailor\Classes\Blueprint\StreamBlueprint;
use Tailor\Classes\Blueprint\StructureBlueprint;

class BlueprintTest extends TestCase
{
    private $blueprintState = [];

    public function setUp(): void
    {
        parent::setUp();
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
        parent::tearDown();
    }

    public function testLoadBlueprints()
    {
        $blueprint = Blueprint::load('blog/posts.yaml');

        $this->assertInstanceOf(\Tailor\Classes\Blueprint\EntryBlueprint::class, $blueprint);
        $this->assertEquals('Posts', $blueprint->name);
        $this->assertEquals('blog', $blueprint->handle);
        $this->assertEquals('blog/posts.yaml', $blueprint->fileName);
    }

    public function testListBlueprints()
    {
        $blueprints = Blueprint::listInProject();
        $this->assertArrayIsEqual([
            'blog/authors.yaml',
            'blog/categories.yaml',
            'blog/config.yaml',
            'blog/post-content.yaml',
            'blog/posts.yaml',
            'landing/blockbuilder.yaml',
            'landing/blockbuilder/call-to-action.yaml',
            'landing/blockbuilder/carousel.yaml',
            'landing/blockbuilder/common.yaml',
            'landing/blockbuilder/compare-table.yaml',
            'landing/blockbuilder/feature-table.yaml',
            'landing/blockbuilder/featurette.yaml',
            'landing/blockbuilder/headline-items.yaml',
            'landing/blockbuilder/headline.yaml',
            'landing/blockbuilder/pricing-table.yaml',
            'landing/landing-page.yaml',
            'october-test/collections/basic.yaml',
            'october-test/globals/basic.yaml',
            'october-test/mixins/collection-field.yaml',
            'october-test/mixins/entry-field.yaml',
            'october-test/sections/feed-basic.yaml',
            'october-test/sections/solo-basic.yaml',
            'october-test/sections/tree-basic.yaml',
            'wiki/wiki.yaml'
        ], $blueprints->pluck('fileName')->all());

        $blueprints = EntryBlueprint::listInProject();
        $this->assertArrayIsEqual([
            'blog/authors.yaml',
            'blog/categories.yaml',
            'blog/posts.yaml',
            'landing/landing-page.yaml',
            'october-test/collections/basic.yaml',
            'october-test/globals/basic.yaml',
            'october-test/sections/feed-basic.yaml',
            'october-test/sections/solo-basic.yaml',
            'october-test/sections/tree-basic.yaml',
            'wiki/wiki.yaml'
        ], $blueprints->pluck('fileName')->all());

        $blueprints = GlobalBlueprint::listInProject();
        $this->assertArrayIsEqual([
            "blog/config.yaml"
        ], $blueprints->pluck('fileName')->all());

        $blueprints = MixinBlueprint::listInProject();
        $this->assertArrayIsEqual([
            "blog/post-content.yaml",
            "landing/blockbuilder/call-to-action.yaml",
            "landing/blockbuilder/carousel.yaml",
            "landing/blockbuilder/common.yaml",
            "landing/blockbuilder/compare-table.yaml",
            "landing/blockbuilder/feature-table.yaml",
            "landing/blockbuilder/featurette.yaml",
            "landing/blockbuilder/headline-items.yaml",
            "landing/blockbuilder/headline.yaml",
            "landing/blockbuilder/pricing-table.yaml",
            "landing/blockbuilder.yaml",
            "october-test/mixins/collection-field.yaml",
            "october-test/mixins/entry-field.yaml"
        ], $blueprints->pluck('fileName')->all());

        $blueprints = SingleBlueprint::listInProject();
        $this->assertArrayIsEqual([
            "landing/landing-page.yaml",
            "october-test/sections/solo-basic.yaml"
        ], $blueprints->pluck('fileName')->all());

        $blueprints = StreamBlueprint::listInProject();
        $this->assertArrayIsEqual([
            "blog/posts.yaml",
            "october-test/sections/feed-basic.yaml"
        ], $blueprints->pluck('fileName')->all());

        $blueprints = StructureBlueprint::listInProject();
        $this->assertArrayIsEqual([
            "october-test/sections/tree-basic.yaml",
            "wiki/wiki.yaml"
        ], $blueprints->pluck('fileName')->all());
    }

    public function testAssignUuid()
    {
        $fixturesPath = base_path('modules/tailor/tests/fixtures/blueprints/blog/comments.stub');
        $tempDirectory = sys_get_temp_dir() . '/wesria-blueprint-' . bin2hex(random_bytes(8));
        mkdir($tempDirectory);
        $tempBlueprint = $tempDirectory . '/comments.yaml';

        try {
            copy($fixturesPath, $tempBlueprint);

            $blueprint = Blueprint::inDatasource($tempDirectory)->find('comments.yaml');
            $this->assertEquals('Comments', $blueprint->name);
            $this->assertEquals('comments.yaml', $blueprint->fileName);
            $this->assertNull($blueprint->uuid);
            $blueprint->save();
            $uuid = $blueprint->uuid;

            $blueprint = Blueprint::inDatasource($tempDirectory)->find('comments.yaml');
            $this->assertNotNull($blueprint->uuid);
            $this->assertSame($uuid, $blueprint->uuid);
        }
        finally {
            unlink($tempBlueprint);
            rmdir($tempDirectory);
        }
    }

    /**
     * assertArrayIsEqual
     */
    protected function assertArrayIsEqual($arr, $exp)
    {
        sort($arr);
        sort($exp);

        $this->assertEquals($exp, $arr);
    }
}
