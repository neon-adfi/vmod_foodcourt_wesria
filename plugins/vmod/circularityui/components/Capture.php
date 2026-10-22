<?php namespace Vmod\CircularityUi\Components;

use Flash;
use Redirect;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use October\Rain\Exception\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Vmod\Core\Classes\Workspace;
use Vmod\Core\Facades\Auth;
use Vmod\CircularityUi\Classes\CircularityClient;

class Capture extends \Cms\Classes\ComponentBase
{
    public function componentDetails()
    {
        return ['name' => 'Circularity capture', 'description' => 'Court areas and measurement capture through the API'];
    }

    public function defineProperties()
    {
        return ['view' => ['title' => 'View', 'default' => 'entries', 'type' => 'string']];
    }

    public function onRun()
    {
        if (!Auth::getUser()) {
            return Redirect::to('/auth');
        }
        $member = Workspace::authorize('circularity.read');
        $rules = ['uuid' => 'nullable|uuid', 'new' => 'nullable|in:1', 'id' => 'nullable|integer|min:1',
            'branch_ref' => 'nullable|string|max:128', 'area_id' => 'nullable|integer|min:1',
            'measurement_date' => 'nullable|date_format:Y-m-d|prohibits:date_from,date_to',
            'date_from' => 'nullable|required_with:date_to|date_format:Y-m-d',
            'date_to' => 'nullable|required_with:date_from|date_format:Y-m-d',
            'measurement_type' => 'nullable|string|in:food_waste,packaging_waste,mixed_waste,reusable_issued,reusable_returned',
            'destination' => 'nullable|string|in:unknown,landfill,incineration,recycling,composting,anaerobic_digestion,animal_feed,other,not_applicable',
            'page' => 'nullable|integer|min:1|max:1000000'];
        if ($this->property('view') === 'reporting') {
            $rules['report'] = 'nullable|in:period,area,daily,court';
        }
        $validator = \Validator::make(get(), $rules);
        $validator->after(function ($validator) {
            // Compare only validated dates; Laravel's cross-field date rule can throw on array input.
            if ($validator->errors()->isEmpty() && get('date_from') && get('date_to') && get('date_to') < get('date_from')) {
                $validator->errors()->add('date_to', 'The end date must be on or after the start date.');
            }
        });
        if ($validator->fails()) {
            $this->queryError($validator->errors()->toArray(), $rules);
            return;
        }
        $filters = $validator->validated();
        $branch = Workspace::branchId() !== null ? (string) Workspace::branchId() : ($filters['branch_ref'] ?? null);
        if (!empty($filters['new']) && empty($filters['uuid']) && $this->property('view') === 'entries') {
            return Redirect::to('/circularity/entries?' . http_build_query(array_filter([
                'new' => 1, 'uuid' => (string) Str::uuid(), 'branch_ref' => $branch,
            ])));
        }
        $this->page['workspaceMember'] = $member;
        $this->page['workspaceToken'] = Workspace::token();
        $this->page['access'] = array_fill_keys($member->role->permissions ?? [], true);
        $this->page['branches'] = $member->branches()
            ->when(Workspace::branchId() !== null, fn ($query) => $query->where('vmod_core_branches.id', Workspace::branchId()))->get();
        $this->page['courtNames'] = $this->page['branches']->pluck('name', 'id')->all();
        $this->page['filters'] = $filters;
        $this->page['selectedCourt'] = $branch;
        $client = new CircularityClient;
        try {
            $this->page['options'] = $client->request('GET', '/options');
            $areas = $client->request('GET', '/areas', [], $branch)['areas'];
            $this->page['areas'] = $areas;
            $this->page['areaNames'] = array_column($areas, 'name', 'id');
            if ($this->property('view') === 'areas') {
                $area = empty($filters['id']) ? null : collect($areas)->firstWhere('id', (int) $filters['id']);
                abort_if(!empty($filters['id']) && !$area, 404);
                $this->page['area'] = $area;
                return;
            }
            if ($this->property('view') === 'reporting') {
                $report = $filters['report'] ?? 'period';
                $this->page['reportOptions'] = ['period' => 'Period comparison', 'area' => 'Area / stall breakdown',
                    'daily' => 'Daily trend', 'court' => 'Court comparison'];
                $scope = array_intersect_key($filters, array_flip([
                    'branch_ref', 'area_id', 'measurement_date', 'date_from', 'date_to', 'measurement_type', 'destination',
                ]));
                $groupBy = ['area' => 'area', 'daily' => 'date', 'court' => 'branch'][$report] ?? null;
                $summary = $client->request('GET', '/summary', $groupBy ? $scope + ['group_by' => $groupBy] : $scope, $branch);
                $scope = $summary['filters'];
                if ($branch !== null && $branch !== '') {
                    $scope['branch_ref'] = $branch;
                }
                $this->page['report'] = $report;
                $this->page['filters'] = $scope;
                $this->page['summary'] = $summary;
                $this->page['summaryLinks'] = $this->summaryLinks($summary, $scope);
                if ($groupBy) {
                    $this->page['reportRows'] = $this->reportingRows($summary, $scope, $report);
                    return;
                }
                $timezone = new \DateTimeZone($summary['reporting_timezone']);
                $from = new \DateTimeImmutable($scope['date_from'], $timezone);
                $to = new \DateTimeImmutable($scope['date_to'], $timezone);
                $days = $from->diff($to)->days + 1;
                $previous = array_replace($scope, [
                    'date_from' => $from->sub(new \DateInterval('P' . $days . 'D'))->format('Y-m-d'),
                    'date_to' => $from->sub(new \DateInterval('P1D'))->format('Y-m-d'),
                ]);
                // Two live reads: keep the saved measurements editable; no frozen comparison snapshot.
                try {
                    $comparison = $client->request('GET', '/summary', $previous, $branch);
                    $this->page['comparison'] = $comparison;
                    $this->page['comparisonLinks'] = $this->summaryLinks($comparison, $previous);
                } catch (HttpExceptionInterface $exception) {
                    if (!in_array($exception->getStatusCode(), [429, 503], true)) {
                        throw $exception;
                    }
                    $this->page['comparisonUnavailable'] = true;
                }
                return;
            }
            if ($this->property('view') === 'overview') {
                $summary = $client->request('GET', '/summary', array_diff_key($filters, array_flip(['uuid', 'id', 'new', 'page'])), $branch);
                $scope = $summary['filters'];
                if ($branch !== null && $branch !== '') {
                    $scope['branch_ref'] = $branch;
                }
                $this->page['filters'] = $scope;
                $this->page['summary'] = $summary;
                $this->page['measurementUrl'] = '/circularity/entries?' . http_build_query($scope);
                $links = [];
                foreach ($summary['totals'] as $type => $total) {
                    $typeScope = array_replace($scope, ['measurement_type' => $type]);
                    $links[$type]['total'] = '/circularity/entries?' . http_build_query($typeScope);
                    foreach ($total['destinations'] as $row) {
                        $links[$type]['destinations'][$row['destination']] = '/circularity/entries?'
                            . http_build_query(array_replace($typeScope, ['destination' => $row['destination']]));
                    }
                }
                $this->page['summaryLinks'] = $links;
                return;
            }
            $entry = null;
            if (!empty($filters['uuid'])) {
                try {
                    $entry = $client->request('GET', '/entries/' . strtolower($filters['uuid']), [], $branch)['entry'];
                } catch (HttpExceptionInterface $exception) {
                    if ($exception->getStatusCode() !== 404 || empty($filters['new'])) {
                        throw $exception;
                    }
                }
            }
            $this->page['entry'] = $entry;
            $this->page['entryUuid'] = $entry['uuid'] ?? ($filters['uuid'] ?? '');
            if ($entry && $entry['status'] === 'active') {
                $this->page['selectedCourt'] = $entry['branch_ref'];
            }
            if (empty($filters['uuid'])) {
                if (!empty($filters['measurement_date'])) {
                    $filters['date_from'] = $filters['date_to'] = $filters['measurement_date'];
                    unset($filters['measurement_date']);
                    $this->page['filters'] = $filters;
                }
                $list = $client->request('GET', '/entries', array_diff_key($filters, array_flip(['uuid', 'id', 'new'])), $branch);
                $this->page['records'] = $list;
                foreach (['previous' => -1, 'next' => 1] as $name => $offset) {
                    $page = $list['current_page'] + $offset;
                    $this->page[$name . 'Url'] = $page > 0 && $page <= $list['last_page']
                        ? '/circularity/entries?' . http_build_query(array_replace($filters, ['page' => $page])) : null;
                }
            }
        } catch (ValidationException $exception) {
            $this->queryError($exception->errors(), $rules);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 503 && $exception->getStatusCode() !== 429) {
                throw $exception;
            }
            $this->controller->setStatusCode($exception->getStatusCode());
            $this->page['circularityUnavailable'] = true;
        }
    }

    private function queryError(array $errors, array $rules): void
    {
        $this->controller->setStatusCode(400);
        $this->page['queryErrors'] = $errors;
        $values = array_replace(array_fill_keys(array_keys($errors), ''), get());
        $this->page['queryValues'] = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', array_intersect_key($values, $rules));
        $this->page['queryPath'] = \Request::url();
    }

    private function summaryLinks(array $summary, array $scope): array
    {
        $links = [];
        foreach ($summary['totals'] as $type => $total) {
            $typeScope = array_replace($scope, ['measurement_type' => $type]);
            $links[$type]['total'] = '/circularity/entries?' . http_build_query($typeScope);
            foreach ($total['destinations'] as $row) {
                $links[$type]['destinations'][$row['destination']] = '/circularity/entries?'
                    . http_build_query(array_replace($typeScope, ['destination' => $row['destination']]));
            }
        }
        return $links;
    }

    private function reportingRows(array $summary, array $scope, string $report): array
    {
        $groups = array_column($summary['groups'], 'totals', 'key');
        $labels = [];
        if ($report === 'area') {
            foreach ($this->page['areas'] as $area) {
                if ((!empty($scope['area_id']) && (string) $area['id'] !== (string) $scope['area_id'])
                    || (!empty($scope['branch_ref']) && $area['branch_ref'] !== $scope['branch_ref'])) {
                    continue;
                }
                if ($area['is_active'] || isset($groups[$area['id']])) {
                    $labels[$area['id']] = $area['name'] . ' · ' . $this->page['courtNames'][$area['branch_ref']]
                        . ($area['is_active'] ? '' : ' (inactive)');
                }
            }
        } elseif ($report === 'court') {
            $area = empty($scope['area_id']) ? null : collect($this->page['areas'])->firstWhere('id', (int) $scope['area_id']);
            foreach ($this->page['courtNames'] as $key => $name) {
                if ((!empty($scope['branch_ref']) && (string) $key !== $scope['branch_ref'])
                    || ($area && (string) $key !== $area['branch_ref'])) {
                    continue;
                }
                $labels[$key] = $name;
            }
        } else {
            $labels = array_combine(array_keys($groups), array_keys($groups));
        }
        // Metadata and totals are separate reads: retry if a concurrent area change left a group without a scoped label.
        abort_if(array_diff_key($groups, $labels), 503);
        $rows = [];
        foreach ($labels as $key => $label) {
            $rowScope = array_replace($scope, $report === 'daily'
                ? ['date_from' => (string) $key, 'date_to' => (string) $key]
                : [$report === 'area' ? 'area_id' : 'branch_ref' => (string) $key]);
            $totals = $groups[$key] ?? [];
            $rows[] = ['key' => (string) $key, 'label' => $label, 'totals' => $totals,
                'links' => $this->summaryLinks(['totals' => $totals], $rowScope)];
        }
        return $rows;
    }

    public function onSaveArea()
    {
        return $this->mutation(function () {
            $input = $this->validated(['id' => 'nullable|integer|min:1', 'branch_ref' => 'required|string|max:128']);
            $id = $input['id'] ?? null;
            $result = (new CircularityClient)->request($id ? 'PATCH' : 'POST', '/areas' . ($id ? '/' . $id : ''), post(), $input['branch_ref']);
            Flash::success('Area saved.');
            return Redirect::to('/circularity/areas?id=' . $result['area']['id'] . '&branch_ref=' . rawurlencode($result['area']['branch_ref']));
        });
    }

    public function onSaveEntry()
    {
        return $this->mutation(function () {
            $input = $this->validated(['uuid' => 'required|uuid', 'branch_ref' => 'required|string|max:128', 'editing' => 'required|boolean']);
            $result = (new CircularityClient)->request(
                $input['editing'] ? 'PATCH' : 'POST',
                '/entries' . ($input['editing'] ? '/' . strtolower($input['uuid']) : ''),
                post(),
                $input['branch_ref']
            );
            Flash::success('Measurement saved.');
            return Redirect::to('/circularity/entries?uuid=' . $result['entry']['uuid']);
        });
    }

    public function onRemoveEntry()
    {
        return $this->mutation(function () {
            $input = $this->validated(['uuid' => 'required|uuid', 'branch_ref' => 'required|string|max:128']);
            (new CircularityClient)->request('DELETE', '/entries/' . strtolower($input['uuid']), post(), $input['branch_ref']);
            Flash::success('Measurement removed.');
            return Redirect::to('/circularity/entries');
        });
    }

    public function onCheckEntry()
    {
        $input = $this->validated(['uuid' => 'required|uuid']);
        try {
            $entry = (new CircularityClient)->request('GET', '/entries/' . strtolower($input['uuid']))['entry'];
            // Checking never changes the displayed edit version or the user's entered values.
            return ['circularityCheck' => ['status' => $entry['status'], 'uuid' => $entry['uuid']]];
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 404) {
                return ['circularityCheck' => ['status' => 'not_found', 'uuid' => strtolower($input['uuid'])]];
            }
            return ajax()->error('The saved entry could not be checked. The earlier save outcome remains uncertain.', $exception->getStatusCode());
        }
    }

    public function onGroup()
    {
        $input = $this->validated(['branch_ref' => 'required|string|max:128', 'area_id' => 'required|integer|min:1',
            'measurement_date' => 'required|date_format:Y-m-d', 'measurement_type' => 'required|string|max:30',
            'destination' => 'required|string|max:30']);
        try {
            $rows = (new CircularityClient)->request(
                'GET',
                '/entries',
                array_diff_key($input, ['destination' => true]),
                $input['branch_ref']
            )['data'];
            $existing = collect($rows)->first(fn ($row) => $row['mode'] === 'daily_total' && $row['destination'] === $input['destination']);
            return ['circularityGroup' => ['mode' => $rows[0]['mode'] ?? null, 'uuid' => $existing['uuid'] ?? null]];
        } catch (HttpExceptionInterface $exception) {
            return ajax()->error('The current input mode could not be checked. Try again before saving.', $exception->getStatusCode());
        }
    }

    private function mutation(callable $operation)
    {
        try {
            return $operation();
        } catch (HttpResponseException $exception) {
            $data = json_decode($exception->getResponse()->getContent(), true);
            return ajax()->error($data['message'], 409)->data(['circularityConflict' => array_intersect_key($data, ['code' => true, 'uuid' => true])]);
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();
            $message = $status === 503
                ? 'The circularity service is unavailable. Save outcome unknown. Check the saved entry before retrying.'
                : $exception->getMessage();
            return ajax()->error($message, $status)->data(['circularityUncertain' => in_array($status, [409, 503], true)]);
        }
    }

    private function validated(array $rules): array
    {
        $validator = \Validator::make(post(), $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        return $validator->validated();
    }
}
