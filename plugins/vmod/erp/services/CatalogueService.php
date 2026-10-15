<?php namespace Vmod\Erp\Services;

use DB;
use Validator;
use Illuminate\Database\QueryException;
use October\Rain\Exception\ValidationException;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Models\Company;
use Vmod\Core\Models\Branch;
use Vmod\Core\Models\Audit;
use Vmod\Erp\Classes\DocumentTotals;
use Vmod\Erp\Models\Party;
use Vmod\Erp\Models\Product;
use Vmod\Erp\Models\ProductPackage;
use Vmod\Erp\Models\SupplierOffer;
use Vmod\Erp\Models\TaxRate;
use Vmod\Erp\Models\StockLocation;

class CatalogueService
{
    public function saveParty(array $input): Party
    {
        return $this->mutate($input, function ($actor) use ($input) {
            $data = $this->validate($input, [
                'code' => 'required|string|max:80', 'name' => 'required|string|max:255',
                'is_customer' => 'required|boolean', 'is_supplier' => 'required|boolean',
                'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:80', 'address' => 'nullable|string|max:2000',
            ]);
            if (!$data['is_customer'] && !$data['is_supplier']) {
                throw new ValidationException(['is_customer' => 'Select customer, supplier, or both.']);
            }
            $record = $this->editable('parties', $input, $actor);
            return $this->save($record, $data, 'parties', $actor);
        });
    }

    public function saveProduct(array $input): Product
    {
        return $this->mutate($input, function ($actor) use ($input) {
            $data = $this->validate($input, [
                'sku' => 'required|string|max:80', 'name' => 'required|string|max:255',
                'base_uom' => 'required|string|max:30', 'description' => 'nullable|string|max:2000',
            ]);
            $record = $this->editable('products', $input, $actor);
            if ($record->exists && $data['base_uom'] !== $record->base_uom) {
                // A locking read is essential after waiting: REPEATABLE READ may already hold an older auth snapshot.
                $package = ProductPackage::where('company_id', $actor->company_id)->where('product_id', $record->id)->lockForUpdate()->first();
                abort_if($package, 409, 'Base UOM is locked after the first package. Create a replacement product.');
            }
            return $this->save($record, $data, 'products', $actor);
        });
    }

    public function savePackage(array $input): ProductPackage
    {
        return $this->mutate($input, function ($actor, $company) use ($input) {
            $data = $this->validate($input, ['code' => 'required|string|max:80', 'name' => 'required|string|max:255']);
            $price = $input['sales_price'] ?? null;
            $data['sales_price'] = $price === null || $price === '' ? null : DocumentTotals::decimal($price, 'sales_price');
            // Parent identity is immutable; an advisory read is only used to choose which parent to lock first.
            $existing = empty($input['id']) ? null : $this->query('packages', $actor)->findOrFail($input['id']);
            $productId = $existing ? $existing->product_id : $this->identifier($input, 'product_id');
            $product = $this->parent('products', $productId, $actor);
            $record = $this->editable('packages', $input, $actor);
            $this->unchanged($input, 'product_id', $productId);
            $values = $data;
            if ($record->exists) {
                if (array_key_exists('conversion', $input)) {
                    abort_unless(DocumentTotals::decimal($input['conversion'], 'conversion', true) === $record->conversion, 409, 'Pack size is fixed. Create a new package.');
                }
            } else {
                $this->active($product, 'product_id');
                $expected = $this->validate($input, ['expected_base_uom' => 'required|string|max:30']);
                abort_unless($expected['expected_base_uom'] === $product->base_uom, 409, 'Product base unit changed. Reload before creating a package.');
                $record->product_id = $product->id;
                $values['conversion'] = DocumentTotals::decimal($input['conversion'] ?? null, 'conversion', true);
            }
            if ($values['sales_price'] !== null) {
                $this->lockCurrency($company);
            }
            return $this->save($record, $values, 'packages', $actor);
        }, isset($input['sales_price']) && $input['sales_price'] !== '');
    }

    public function saveOffer(array $input): SupplierOffer
    {
        return $this->mutate($input, function ($actor, $company) use ($input) {
            $price = DocumentTotals::decimal($input['unit_price'] ?? null, 'unit_price');
            $existing = empty($input['id']) ? null : $this->query('offers', $actor)->findOrFail($input['id']);
            $supplierId = $existing ? $existing->supplier_id : $this->identifier($input, 'supplier_id');
            $packageId = $existing ? $existing->package_id : $this->identifier($input, 'package_id');
            $supplier = $this->parent('parties', $supplierId, $actor);
            if (!$existing) {
                $this->active($supplier, 'supplier_id');
                if (!$supplier->is_supplier) {
                    throw new ValidationException(['supplier_id' => 'Choose a supplier.']);
                }
            }
            $package = $this->lockedPackage($packageId, $actor, !$existing);
            $record = $this->editable('offers', $input, $actor);
            $this->unchanged($input, 'supplier_id', $supplierId);
            $this->unchanged($input, 'package_id', $packageId);
            $record->supplier_id = $supplier->id;
            $record->package_id = $package->id;
            $this->lockCurrency($company);
            return $this->save($record, ['unit_price' => $price], 'offers', $actor);
        }, true);
    }

    public function saveTaxRate(array $input): TaxRate
    {
        return $this->mutate($input, function ($actor) use ($input) {
            $data = $this->validate($input, ['name' => 'required|string|max:80']);
            $data['rate'] = DocumentTotals::decimal($input['rate'] ?? null, 'rate', false, '100');
            return $this->save($this->editable('tax_rates', $input, $actor), $data, 'tax_rates', $actor);
        });
    }

    public function saveLocation(array $input): StockLocation
    {
        return $this->mutate($input, function ($actor) use ($input) {
            $data = $this->validate($input, ['code' => 'required|string|max:80', 'name' => 'required|string|max:255']);
            $record = $this->editable('locations', $input, $actor);
            $branchId = $record->exists ? $record->branch_id : $this->identifier($input, 'branch_id');
            $this->unchanged($input, 'branch_id', $branchId);
            abort_unless(in_array((int) $branchId, $actor->branchIds(), true) && (!Workspace::branchId() || Workspace::branchId() === (int) $branchId), 404);
            Branch::where('company_id', $actor->company_id)->whereKey($branchId)->sharedLock()->firstOrFail();
            $record->branch_id = $branchId;
            return $this->save($record, $data, 'locations', $actor);
        });
    }

    public function setActive(string $kind, array $input)
    {
        return $this->mutate($input, function ($actor) use ($kind, $input) {
            $data = $this->validate($input, ['id' => 'required|integer|min:1', 'is_active' => 'required|boolean']);
            $record = $this->editable($kind, $input, $actor);
            $before = $record->attributesToArray();
            $record->is_active = (bool) $data['is_active'];
            return $this->persist($record, $before, $kind, $actor);
        });
    }

    public function listing(string $kind, string $search = '', string $status = 'active')
    {
        $query = $this->query($kind, Workspace::authorize('catalogue.read'));
        $query->with(match ($kind) {
            'packages' => ['product'], 'offers' => ['supplier', 'package.product'], 'locations' => ['branch'], default => [],
        });
        if ($status === 'active') {
            $this->eligible($query, $kind);
        } elseif ($status === 'inactive') {
            $query->whereNot(fn ($query) => $this->eligible($query, $kind));
        } elseif ($status !== 'all') {
            throw new ValidationException(['status' => 'Choose active, inactive or all.']);
        }
        if ($search !== '') {
            $term = '%' . addcslashes(mb_substr($search, 0, 100), '%_\\') . '%';
            if ($kind === 'offers') {
                $query->where(fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('name', 'like', $term))
                    ->orWhereHas('package', fn ($p) => $p->where('name', 'like', $term)->orWhere('code', 'like', $term)));
            } else {
                $query->where(function ($q) use ($term, $kind) {
                    $q->where('name', 'like', $term);
                    if ($kind !== 'tax_rates') {
                        $q->orWhere($kind === 'products' ? 'sku' : 'code', 'like', $term);
                    }
                });
            }
        }
        return $query->orderBy('id', 'desc')->paginate(25);
    }

    public function find(string $kind, int $id)
    {
        return $this->query($kind, Workspace::authorize('catalogue.read'))->findOrFail($id);
    }

    public function choices(string $kind)
    {
        $query = $this->query($kind, Workspace::authorize('catalogue.read'));
        $this->eligible($query, $kind);
        // Native selects suit the small MVP catalogue; replace with scoped AJAX search if measured list size warrants it.
        return $query->orderBy('id')->get();
    }

    public function snapshot(array $input): array
    {
        return $this->mutate($input, function ($actor, $company) use ($input) {
            $this->validate($input, ['offer_id' => 'nullable|integer|min:1', 'tax_rate_id' => 'nullable|integer|min:1']);
            $quantity = DocumentTotals::decimal($input['quantity'] ?? null, 'quantity', true);
            $party = $this->parent('parties', $this->identifier($input, 'party_id'), $actor);
            $this->active($party, 'party_id');
            $package = $this->lockedPackage($this->identifier($input, 'package_id'), $actor);
            $product = $this->parent('products', $package->product_id, $actor);
            $price = $package->sales_price;
            $offerId = null;
            if (!empty($input['offer_id'])) {
                $offer = $this->parent('offers', $this->identifier($input, 'offer_id'), $actor);
                abort_unless((int) $offer->supplier_id === (int) $party->id && (int) $offer->package_id === (int) $package->id, 404);
                $this->active($offer, 'offer_id');
                if (!$party->is_supplier) {
                    throw new ValidationException(['party_id' => 'Choose a supplier for this offer.']);
                }
                $price = $offer->unit_price;
                $offerId = $offer->id;
            } elseif (!$party->is_customer) {
                throw new ValidationException(['party_id' => 'Choose a customer for a sales list price.']);
            }
            $rate = null;
            if (!empty($input['tax_rate_id'])) {
                $rate = $this->parent('tax_rates', $this->identifier($input, 'tax_rate_id'), $actor);
                $this->active($rate, 'tax_rate_id');
            }
            return [
                'currency' => $company->currency, 'price_mode' => 'exclusive',
                'party' => $party->only(['id', 'code', 'name', 'email', 'phone', 'address']),
                'line' => [
                    'product_id' => $product->id, 'sku' => $product->sku, 'description' => $product->description ?: $product->name,
                    'package_id' => $package->id, 'package_name' => $package->name, 'package_code' => $package->code,
                    'base_uom' => $product->base_uom, 'conversion' => $package->conversion,
                    'quantity' => $quantity, 'base_quantity' => DocumentTotals::baseQuantity($quantity, $package->conversion),
                    'offer_id' => $offerId, 'unit_price' => $price, 'tax_rate_id' => $rate ? $rate->id : null,
                    'tax_label' => $rate ? $rate->name : null, 'tax_rate' => $rate ? $rate->rate : '0.0000',
                ],
            ];
        }, false, 'catalogue.read');
    }

    private function mutate(array $input, callable $callback, bool $priced = false, string $permission = 'catalogue.write')
    {
        Workspace::assertContext();
        $companyId = Workspace::authorize($permission)->company_id;
        $this->validate($input, ['id' => 'nullable|integer|min:1', 'version' => 'required_with:id|integer|min:1']);
        if ($priced || array_key_exists('expected_currency', $input)) {
            $this->validate($input, ['expected_currency' => 'required|string|size:3']);
        }
        // The monotonic flag is advisory outside the transaction: an old null only takes an extra exclusive lock.
        $exclusive = $priced && !Company::whereKey($companyId)->value('currency_locked_at');
        try {
            return DB::transaction(function () use ($companyId, $exclusive, $permission, $input, $callback) {
                // First transactional read must be this lock, before fresh authorization establishes a snapshot.
                $query = Company::whereKey($companyId);
                $company = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail();
                Workspace::assertContext();
                $actor = Workspace::authorize($permission);
                abort_unless((int) $actor->company_id === (int) $companyId, 409);
                if (isset($input['expected_currency'])) {
                    abort_unless($input['expected_currency'] === $company->currency, 409, 'Currency changed. Reload before saving.');
                }
                return $callback($actor, $company);
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                foreach (['erp_party_code' => 'code', 'erp_product_sku' => 'sku', 'erp_package_code' => 'code', 'erp_offer_supplier_package' => 'package_id', 'erp_tax_name' => 'name', 'erp_location_code' => 'code'] as $key => $field) {
                    if (str_contains($exception->getMessage(), $key)) {
                        throw new ValidationException([$field => 'This identifier already exists, including inactive records. Edit or restore the existing record.']);
                    }
                }
            }
            throw $exception;
        }
    }

    private function query(string $kind, $actor)
    {
        $class = match ($kind) {
            'parties' => Party::class, 'products' => Product::class, 'packages' => ProductPackage::class,
            'offers' => SupplierOffer::class, 'tax_rates' => TaxRate::class, 'locations' => StockLocation::class,
            default => abort(404),
        };
        $query = $class::where('company_id', $actor->company_id);
        if ($kind === 'locations') {
            $query->whereIn('branch_id', Workspace::branchId() ? [Workspace::branchId()] : $actor->branchIds());
        }
        return $query;
    }

    private function eligible($query, string $kind): void
    {
        $query->where('is_active', true);
        if ($kind === 'packages') {
            $query->whereHas('product', fn ($q) => $q->where('is_active', true));
        } elseif ($kind === 'offers') {
            $query->whereHas('supplier', fn ($q) => $q->where('is_active', true)->where('is_supplier', true))
                ->whereHas('package', fn ($q) => $q->where('is_active', true)->whereHas('product', fn ($p) => $p->where('is_active', true)));
        }
    }

    private function editable(string $kind, array $input, $actor)
    {
        $query = $this->query($kind, $actor);
        if (empty($input['id'])) {
            $record = $query->getModel()->newInstance();
            $record->company_id = $actor->company_id;
            $record->version = 0;
            return $record;
        }
        $record = $query->whereKey($input['id'])->lockForUpdate()->firstOrFail();
        abort_unless((int) $record->version === (int) ($input['version'] ?? 0), 409, 'This record changed. Reload before saving.');
        return $record;
    }

    private function parent(string $kind, int $id, $actor)
    {
        return $this->query($kind, $actor)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockedPackage(int $id, $actor, bool $requireActive = true): ProductPackage
    {
        $advisory = $this->query('packages', $actor)->findOrFail($id);
        $product = $this->parent('products', $advisory->product_id, $actor);
        $package = $this->parent('packages', $id, $actor);
        if ($requireActive) {
            $this->active($product, 'package_id');
            $this->active($package, 'package_id');
        }
        return $package;
    }

    private function active($record, string $field): void
    {
        if (!$record->is_active) {
            throw new ValidationException([$field => 'This record or its parent is inactive. Restore it before use.']);
        }
    }

    private function identifier(array $input, string $field): int
    {
        return (int) $this->validate($input, [$field => 'required|integer|min:1'])[$field];
    }

    private function unchanged(array $input, string $field, int $value): void
    {
        if (array_key_exists($field, $input)) {
            abort_unless($this->identifier($input, $field) === $value, 409, 'Record ownership is fixed. Create a new record.');
        }
    }

    private function validate(array $input, array $rules): array
    {
        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        return $validator->validated();
    }

    private function lockCurrency(Company $company): void
    {
        if (!$company->currency_locked_at) {
            $company->currency_locked_at = now();
            $company->save();
        }
    }

    private function save($record, array $data, string $kind, $actor)
    {
        $before = $record->exists ? $record->attributesToArray() : null;
        $record->fill($data);
        return $this->persist($record, $before, $kind, $actor);
    }

    private function persist($record, ?array $before, string $kind, $actor)
    {
        $created = !$record->exists;
        $record->version = (int) $record->version + 1;
        $record->save();
        $record->refresh();
        Audit::record(
            $actor->company_id,
            'catalogue.' . $kind . ($created ? '.created' : '.updated'),
            ['record_id' => $record->id, 'before' => $before, 'after' => $record->attributesToArray()],
            $actor->user_id
        );
        return $record;
    }
}
