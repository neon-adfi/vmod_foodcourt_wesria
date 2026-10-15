# October CMS Plugin Development Standards

> **Audience:** AI agents (Claude, Codex) generating October CMS plugin code.
> **Source of truth:** Derived from `neon/channel` plugin patterns.
> **Companion:** Skeleton templates in `./skeletons/`

---

## Wesria application contract

Apply `vmod-wesria-erp-mvp-plan-2026-10-15.md` first: October 4.3/Laravel 12, fresh Vmod code and no legacy plugin dependency chain. The generic skeletons below are historical reference patterns, not mandatory base classes/traits.

- Frontend audit uses explicit server-derived `actor_user_id` referencing `users.id`, source and time. The older `created_by_id`/BackendAuth pattern is admin-only; frontend actions leave such fields null. Never infer a frontend actor from a backend session.
- Use only traits/helpers needed by actual call sites. Do not prebuild EnumBase, JSON meta or audit abstractions merely to satisfy the skeleton.
- Transactional service failures must propagate and roll back. The historical catch-and-return-null / partial-success examples are not appropriate for ERP posting or atomic operations; safe presentation of errors belongs at the request boundary.
- Test through `Vmod\Tests\ApplicationTestCase` with the guarded MySQL bootstrap described in `runtime-phase-1.md`. Keep frontend CSRF enabled.
- PrintCore/FCM/Gaia notification examples are outside the ERP MVP; native authentication/recovery mail remains in scope.

## 1. Core Dependencies

Every plugin depends on a core plugin providing these traits and base classes. Build these first.

### HasAuditTracking trait

- Auto-populates `created_source` (tinyint: 0=manual, 1=API, 2=system/job) and `created_by_id` (nullable FK to `backend_users`) on model create
- No behavior on update
- Migration columns: `$table->unsignedTinyInteger('created_source')->default(0)` and `$table->unsignedInteger('created_by_id')->nullable()`

### HasJsonMeta trait

- Declares `$jsonable = ['meta']` on the model
- Casts `meta` to array internally
- Accessor returns pretty-printed JSON string for admin display
- Migration column: `$table->json('meta')->nullable()`
- **Empty-string guard:** Models must coerce empty `meta` to `null` in `beforeValidate()` — MySQL `json` columns reject `''` as invalid JSON. The codeeditor widget sends an empty string when untouched.
  ```php
  if (empty($this->meta)) {
      $this->meta = null;
  }
  ```

### EnumBase base class

Must provide:
- `lists($emptyOption = false)` — returns `[value => label]` array, optionally prepended with empty option
- `get($value, $default = null)` — returns label for a given value, or $default if not found
- `options($model)` — static method compatible with October dropdown `options:` YAML key. Provided by `EnumBase` itself (delegates to `lists()`), so subclasses only implement `lists()`
- `values()` — returns array of valid integer keys
- `resolve($value)` — resolves int, numeric string, or label string to canonical int. Returns null if unresolvable
- `coerce($value)` — like resolve but passes through unknown ints/strings without error
- `label($value)` — returns human-readable label, or 'Unknown' for unresolvable values
- `color($value)` — returns Bootstrap color class. Subclasses SHOULD override. Default: 'secondary'
- Constants as `int` values (tinyint-friendly). Leave numeric gaps between groups when useful.

---

## 2. Model Layer

Every model follows this exact structure, in this order:

```php
<?php namespace Vendor\Plugin\Models;

use Model;
use Vendor\Core\Traits\HasAuditTracking;
use Vendor\Core\Traits\HasJsonMeta;

/**
 * Short description
 *
 * @link https://docs.octobercms.com/3.x/extend/system/models.html
 */
class ModelName extends Model
{
    use \October\Rain\Database\Traits\Validation;
    use HasAuditTracking;
    use HasJsonMeta;

    // 1. Table
    public $table = 'vendor_plugin_table_name';

    // 2. Mass assignment
    protected $fillable = ['name', 'slug', 'status'];

    // 3. Validation
    public $rules = [
        'name' => 'required|string|max:255',
        'slug' => 'required|string|max:100|unique:vendor_plugin_table_name',
    ];

    // 4. Casting
    protected $casts = [
        'status' => 'int',
        'is_active' => 'boolean',
    ];
    protected $dates = ['published_at']; // domain timestamps only — do NOT add deleted_at (SoftDelete handles it)

    // 5. Relationships (October array syntax — only declare arrays that have entries)

    // 6. Scopes
    public function scopeIsActive($query)
    {
        return $query->where('is_active', true);
    }

    // 7. Accessors / Mutators
    public function getStatusLabelAttribute()
    {
        return StatusEnum::get($this->status);
    }

    // 8. Lifecycle hooks (only declare when needed)

    // 9. Business methods
    public function getByKey(string $key): ?self
    {
        return static::where('key', $key)->first();
    }
}
```

### Model rules

- Always use all 3 core traits: Validation, HasAuditTracking, HasJsonMeta
- `$fillable` explicitly lists every writable attribute — no wildcards. Do not use `$guarded`.
- Do not redeclare `$jsonable = ['meta']` or cast `meta` — `HasJsonMeta` handles both
- Do not include `deleted_at` in `$dates` — `SoftDelete` handles it automatically
- Only declare relationship arrays (`$belongsTo`, `$hasMany`, etc.) that have entries — omit empty ones
- Relationships use October's array syntax with explicit `key`, `otherKey`, `table` for `belongsToMany`
- Pivot relations include `'pivot' => [...]` listing pivot columns
- Scopes prefixed `scope`, return query builder
- Accessors for any enum-backed field returning a label
- Only declare lifecycle hooks (`beforeCreate`, `afterCreate`, etc.) when they have content

### Relationship example

```php
public $belongsToMany = [
    'watch_channels' => [
        \Vendor\Crawler\Models\WatchChannel::class,
        'table' => 'vendor_plugin_watch_channel_links',
        'key' => 'channel_id',
        'otherKey' => 'watch_channel_id',
        'pivot' => ['priority', 'is_active', 'meta']
    ]
];
```

### Pivot models

Extend `\October\Rain\Database\Pivot` instead of `Model`. Still use all 3 core traits.

---

## 3. Enum Layer

```php
<?php namespace Vendor\Plugin\Enums;

use Vendor\Core\Enums\EnumBase;

class StatusEnum extends EnumBase
{
    const PENDING = 0;
    const ACTIVE = 1;
    const ARCHIVED = 2;

    static public function lists($emptyOption = false)
    {
        $list = [
            self::PENDING => 'Pending',
            self::ACTIVE => 'Active',
            self::ARCHIVED => 'Archived',
        ];

        if ($emptyOption === false) {
            return $list;
        }

        return array_merge(['' => $emptyOption], $list);
    }

    // Optional: conditional helpers
    public static function isTerminal($value): bool
    {
        return in_array($value, [self::ARCHIVED]);
    }
}
```

### Enum rules

- One enum per concept (status, type, data_source — not combined)
- Constants are `int`, always starting at 0
- `lists()` is mandatory — powers all admin dropdowns
- Class name suffixed with `Enum`
- Used in YAML as `options: Vendor\Plugin\Enums\StatusEnum::options`
- Used in accessors as `StatusEnum::get($this->status)`

---

## 4. Migration Layer

```php
<?php namespace Vendor\Plugin\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        Schema::create('vendor_plugin_table_name', function (Blueprint $table) {
            // 1. Primary key
            $table->increments('id');

            // 2. Foreign keys (grouped together)
            $table->unsignedInteger('parent_id')->nullable()->index();
            $table->foreign('parent_id')->references('id')
                ->on('vendor_plugin_parent_table')->onDelete('cascade');

            // 3. Core data columns
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->text('remark')->nullable();

            // 4. Enum/status columns as tinyint
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedTinyInteger('type')->default(0);

            // 5. Counters
            $table->unsignedBigInteger('view_count')->default(0)->index();

            // 6. Booleans
            $table->boolean('is_active')->default(false);

            // 7. Domain timestamps
            $table->timestamp('published_at')->nullable();

            // 8. Flexible JSON
            $table->json('meta')->nullable();

            // 9. Audit columns
            $table->unsignedTinyInteger('created_source')->default(0);
            $table->unsignedInteger('created_by_id')->nullable();

            // 10. Standard timestamps + soft delete
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('vendor_plugin_table_name');
    }
};
```

### Migration rules

- Table name: `vendor_plugin_pluralmodel` (e.g., `neon_channel_channels`)
- Pivot tables: `vendor_plugin_model1_model2_links`
- Anonymous class migration (`return new class extends Migration`)
- File naming: `create_{table_name}_table.php`
- Column order is mandatory — always follow the numbered groups above
- Keep table name short
- Keep FK name short
- FKs always `nullable()->index()` unless the relationship is required
- `onDelete('cascade')` for ownership relations, `onDelete('set null')` for loose references
- Self-referential FKs added in a second `Schema::table()` call after creation
- Every table gets `meta`, audit columns, `timestamps()`, and `softDeletes()`

---

## 5. Controller Layer

```php
<?php namespace Vendor\Plugin\Controllers;

use BackendMenu;
use Backend\Classes\Controller;

class ModelName extends Controller
{
    // 1. Behaviors
    public $implement = [
        \Backend\Behaviors\FormController::class,
        \Backend\Behaviors\ListController::class,
        // Add RelationController::class only when managing related records
    ];

    // 2. Config references
    public $formConfig = 'config_form.yaml';
    public $listConfig = 'config_list.yaml';
    // public $relationConfig = 'config_relation.yaml'; // only when RelationController is added

    // 3. Permissions
    public $requiredPermissions = ['vendor.plugin.modelnames'];

    // 4. Constructor
    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Vendor.Plugin', 'main-menu', 'side-menu-item');
    }

    // 5. AJAX handlers (only when needed)
    public function onActionName()
    {
        try {
            $id = post('model_id');

            if (!$id) {
                \Flash::error('Missing required reference.');
                return;
            }

            $result = ModelNameService::doAction($id);

            \Flash::success('Action completed successfully.');
        } catch (\Throwable $e) {
            \Log::error('[ModelName] Action failed', [
                'message' => $e->getMessage(),
                'trace' => collect($e->getTrace())->take(5)->toArray(),
            ]);
            \Flash::error('Error performing action.');
        }

        return \Redirect::refresh();
    }
}
```

### Controller rules

- Class name matches model name (singular PascalCase), no `Controller` suffix
- Always implement `FormController` and `ListController`; add `RelationController` only when the controller manages related records
- Permission format: `vendor.plugin.pluralmodelname`
- `BackendMenu::setContext()` in constructor, always 3 args
- AJAX handlers: `on` prefix, PascalCase action name
- Use `post('field')` for request data, never `$_POST` or `request()`
- Business logic lives in service classes — controllers handle request/response only
- Error handling: try/catch with `\Log::error()` + `\Flash::error()`, return `\Redirect::refresh()`
- Log context tag matches controller class name: `[ModelName]`
- Truncate trace to 5 frames: `collect($e->getTrace())->take(5)->toArray()`
- Simple CRUD controllers are just properties + constructor — no extra methods

---

## 6. Admin UI Layer (YAML Configs Only)

View templates are October CMS defaults — do not customize.

### config_form.yaml

```yaml
name: ModelName
form: $/vendor/plugin/models/modelname/fields.yaml
modelClass: Vendor\Plugin\Models\ModelName
defaultRedirect: vendor/plugin/modelname

create:
    title: backend::lang.form.create_title
    redirect: vendor/plugin/modelname/update/:id
    redirectClose: vendor/plugin/modelname

update:
    title: backend::lang.form.update_title
    redirect: vendor/plugin/modelname
    redirectClose: vendor/plugin/modelname

preview:
    title: backend::lang.form.preview_title
```

### config_list.yaml

```yaml
list: $/vendor/plugin/models/modelname/columns.yaml
modelClass: Vendor\Plugin\Models\ModelName
title: Manage ModelNames
recordUrl: vendor/plugin/modelname/update/:id
recordsPerPage: 20
showSetup: true
showSorting: true
defaultSort:
    column: id
    direction: asc
showCheckboxes: true

toolbar:
    buttons: list_toolbar
    search:
        prompt: backend::lang.list.search_prompt
```

### fields.yaml

```yaml
fields:
    id:
        label: ID
        disabled: true
        span: auto
        type: number
        tab: Basic Info

    name:
        label: Name
        span: auto
        type: text
        tab: Basic Info
        commentAbove: "Human readable label"

    slug:
        label: Slug
        span: auto
        type: text
        required: true
        preset: name
        tab: Basic Info

    status:
        label: Status
        type: dropdown
        options: Vendor\Plugin\Enums\StatusEnum::options
        span: auto
        default: 1
        required: true
        tab: Settings

    remark:
        label: Remark
        type: textarea
        size: small
        span: full
        tab: Basic Info

    meta:
        label: Metadata
        size: huge
        span: full
        type: codeeditor
        language: json
        tab: Advanced
        commentAbove: "Additional metadata in JSON format"

    audit:
        type: partial
        tab: Audit
        context: update
        path: $/vendor/core/models/_partials/_created_fields.htm
```

### Field rules

- Tab grouping: `Basic Info` for core fields, `Settings` for enums/flags, `Advanced` for meta/JSON, `Audit` for tracking
- `id` always first, always disabled
- `slug` uses `preset: name` for auto-generation
- Dropdowns reference enum class via `options:` key
- `meta` always `codeeditor` with `language: json`
- Audit partial always `context: update` (hidden on create)
- `commentAbove` for helper text, not `commentBelow`
- `span: auto` for side-by-side, `span: full` for wide fields

### columns.yaml

```yaml
columns:
    id:
        label: ID
        type: number

    name:
        label: Name
        searchable: true
        sortable: true

    status:
        label: Status
        sortable: true
        valueFrom: status_label

    created_at:
        label: Created
        type: datetime
        sortable: true

    updated_at:
        label: Updated
        type: datetime
        sortable: true
```

### Column rules

- `id` first, `type: number` — omit `searchable: false` (it is the default for number columns)
- Enum columns use `valueFrom: {attribute}_label` pointing to the accessor
- Always include `created_at` and `updated_at`
- `searchable: true` on name/title fields, `sortable: true` on most columns
- Omit `type: text` on text columns — it is the default column type

---

## 7. Service Layer

```php
<?php namespace Vendor\Plugin\Classes;

use Illuminate\Support\Facades\Log;
use Vendor\Plugin\Models\ModelName;

class ModelNameService
{
    public static function createRecord(array $data): ?ModelName
    {
        try {
            $model = ModelName::create($data);
            return $model;
        } catch (\Throwable $e) {
            Log::error('[ModelNameService] Create failed', [
                'message' => $e->getMessage(),
                'trace' => collect($e->getTrace())->take(5)->toArray(),
            ]);
            return null;
        }
    }

    public static function bulkProcess(array $items): array
    {
        $results = ['success' => 0, 'failed' => 0];

        foreach ($items as $item) {
            try {
                // Process individual item
                $results['success']++;
            } catch (\Throwable $e) {
                Log::warning('[ModelNameService] Bulk item failed', [
                    'item_id' => $item->id ?? null,
                    'message' => $e->getMessage(),
                ]);
                $results['failed']++;
            }
        }

        return $results;
    }

    public static function queueJob(ModelName $model, array $options = []): bool
    {
        if (!$model->is_active) {
            Log::warning('[ModelNameService] Skipped inactive model', [
                'id' => $model->id,
            ]);
            return false;
        }

        dispatch(new \Vendor\Plugin\Jobs\ProcessModel($model, $options));
        return true;
    }
}
```

### Service rules

- Location: `/classes/` directory
- All methods `public static` — services are stateless utilities
- Return typed results: nullable model for single operations, array for bulk, bool for dispatch
- Null return = failure (caller decides how to report)
- Log tag matches class name: `[ModelNameService]`
- `Log::error()` for exceptions, `Log::warning()` for skipped/partial
- Bulk operations isolate errors per item — one failure doesn't abort the batch
- Validate preconditions before dispatching jobs
- Controllers call services, services call models — never skip the layer

---

## 8. Observer Layer

```php
<?php namespace Vendor\Plugin\Observers;

use Vendor\Plugin\Models\ModelName;

class ModelNameObserver
{
    public function created(ModelName $model)
    {
        dispatch(new \Vendor\Plugin\Jobs\AfterModelCreated($model));
    }

    public function deleted(ModelName $model)
    {
        \Vendor\Plugin\Models\RelatedModel::where('model_id', $model->id)->delete();
    }
}
```

### Registration in Plugin.php boot()

```php
public function boot()
{
    ModelName::observe(ModelNameObserver::class);
}
```

### Observer rules

- One observer per model, only when lifecycle side effects exist
- Class name: `{ModelName}Observer`
- Use for: async job dispatch, cross-plugin cleanup, sync operations
- Do NOT use for: validation (use model `$rules`), simple attribute setting (use model `beforeCreate`)
- Keep observer methods thin — delegate to service classes for complex logic
- Register in `Plugin::boot()`, not in the model

---

## 9. Testing Layer

```php
<?php namespace Vendor\Plugin\Tests;

require_once __DIR__ . '/../../core/tests/PluginTestCase.php';

use Vendor\Tests\PluginTestCase;

class ModelNameServiceTest extends PluginTestCase
{
    public function testCreateRecordReturnsModelOnSuccess(): void
    {
        // Arrange
        $scenario = $this->createScenario([
            'brand' => ['name' => 'Test Brand'],
            'channel' => ['name' => 'Test Channel'],
        ]);

        // Act
        $result = ModelNameService::createRecord([
            'channel_id' => $scenario['channel']->id,
            'name' => 'Test Record',
        ]);

        // Assert
        $this->assertNotNull($result);
        $this->assertSame('Test Record', $result->name);
    }

    public function testCreateRecordReturnsNullOnInvalidData(): void
    {
        $result = ModelNameService::createRecord([]);

        $this->assertNull($result);
    }

    public function testControllerHandlerReturnsSuccess(): void
    {
        $scenario = $this->createScenario([
            'model' => ['name' => 'Test'],
        ]);

        $controller = new \Vendor\Plugin\Controllers\ModelName();
        $this->putRequestData([
            'model_id' => $scenario['model']->id,
        ]);

        $result = $controller->onActionName();

        $this->assertTrue($result['success']);
    }
}
```

### Testing rules

- Location: `/tests/` directory
- Extend shared `PluginTestCase` from core plugin via `require_once` relative path
- File name: `{Feature}Test.php`
- Method naming: `test{What}{Expected}` in camelCase
- Arrange/Act/Assert structure
- Use `createScenario()` for test fixtures
- Test controller handlers directly via `putRequestData()`
- Strict assertions: `assertSame()` over `assertEquals()`
- Cast when comparing floats: `assertSame(1.25, (float) $model->rate)`
- One assertion concept per test

---

## 10. Cross-Cutting Concerns

### Naming Conventions

| Thing | Convention | Example |
|---|---|---|
| Plugin namespace | `Vendor\Plugin` | `Neon\Channel` |
| Table name | `vendor_plugin_pluralmodel` | `neon_channel_channels` |
| Pivot table | `vendor_plugin_model1_model2_links` | `neon_channel_watch_channel_links` |
| Model class | Singular PascalCase | `Channel`, `WatchMatching` |
| Controller class | Singular PascalCase, no suffix | `Channel`, `TemplateConfig` |
| Service class | `{Model}Service` | `WatchMatchingService` |
| Observer class | `{Model}Observer` | `WatchChannelLinkObserver` |
| Enum class | `{Concept}Enum` | `StatusEnum`, `ChannelMatchTypeEnum` |
| Test class | `{Feature}Test` | `TemplateConfigLottieSaveLoadTest` |
| Migration file | `create_{table}_table.php` | `create_neon_channel_channels_table.php` |
| Permission | `vendor.plugin.pluralmodel` | `neon.channel.channels` |
| Menu context | `'Vendor.Plugin', 'main', 'side'` | `'Neon.Channel', 'channel', 'channels'` |
| AJAX handler | `on{Action}` | `onCreateVideoJobManual` |
| Scope | `scope{Name}` | `scopeIsActive` |
| Accessor | `get{Attr}Attribute` | `getStatusLabelAttribute` |
| Variable | camelCase | `$watchMatching` |

#### Class and path casing

- Class declarations, imports, and static references use the exact same PascalCase spelling; the filename matches the class exactly. Example: `DeliveryOrder.php`, `class DeliveryOrder`, `use ...\DeliveryOrder`, and `DeliveryOrder::query()`.
- October runtime directories derived from namespaces are lowercase, even when the PHP namespace segments are PascalCase. Example: `Vmod\PrintCore\Classes\Services\QuoteService` lives at `plugins/vmod/printcore/classes/services/QuoteService.php`.
- Treat casing as Linux case-sensitive even during macOS development. A local successful load does not prove that a mismatched class or path will load in production.
- Apply case-only path changes as explicit Git renames, then regenerate the optimized Composer autoloader before running Artisan commands in production.
- For print and notification runtime paths, run `phpunit plugins/vmod/printcore/tests/Unit/PrintModuleClassPathCaseContractTest.php`; deployment must also run `php scripts/verify-production-autoload.php`.

### Code Style

- PSR-2, 4-space indentation

### Error Handling

- `try/catch (\Throwable $e)` — catch `Throwable`, not `Exception`
- Log tag in brackets matches class name: `[ClassName]`
- Always log: `message`, truncated `trace` (5 frames) — omit `line`, it is redundant with the trace
- `Log::error()` for caught exceptions, `Log::warning()` for skipped/partial
- `Flash::error()` for user-facing, `Flash::success()` for confirmations
- Controllers return `\Redirect::refresh()` after AJAX handlers
- Early return on missing data before try/catch block

### Logging Format

```php
Log::error('[ClassName] Short description', [
    'message' => $e->getMessage(),
    'trace' => collect($e->getTrace())->take(5)->toArray(),
]);
```

For non-exception context:

```php
Log::warning('[ClassName] Skipped reason', [
    'id' => $model->id,
    'field' => $value,
]);
```

---

## 11. Plugin Registration

```php
<?php namespace Vendor\Plugin;

use Backend;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'Plugin Name',
            'description' => 'Description',
            'author' => 'Vendor',
            'icon' => 'icon-leaf'
        ];
    }

    public function boot()
    {
        // Observer registration
        // Event listeners
    }

    public function registerPermissions()
    {
        return [
            'vendor.plugin.modelnames' => [
                'tab' => 'Plugin Name',
                'label' => 'Manage Model Names'
            ],
        ];
    }

    public function registerNavigation()
    {
        return [
            'main-menu' => [
                'label' => 'Plugin Name',
                'url' => Backend::url('vendor/plugin/modelname'),
                'icon' => 'icon-leaf',
                'permissions' => ['vendor.plugin.*'],
                'sideMenu' => [
                    'side-item' => [
                        'label' => 'Model Names',
                        'url' => Backend::url('vendor/plugin/modelname'),
                        'icon' => 'icon-list',
                        'permissions' => ['vendor.plugin.modelnames'],
                    ],
                ]
            ]
        ];
    }
}
```

---

## Directory Structure Reference

```
vendor/plugin/
├── Plugin.php
├── composer.json
├── classes/
│   └── ModelNameService.php
├── controllers/
│   └── modelname/
│       ├── config_form.yaml
│       └── config_list.yaml
├── enums/
│   └── StatusEnum.php
├── models/
│   ├── ModelName.php
│   └── modelname/
│       ├── fields.yaml
│       └── columns.yaml
├── observers/
│   └── ModelNameObserver.php
├── updates/
│   └── create_vendor_plugin_modelnames_table.php
└── tests/
    └── ModelNameServiceTest.php
```
