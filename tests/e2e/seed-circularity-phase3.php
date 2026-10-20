<?php

// Existing guard creates new synthetic companies; seed their measurements through the real HTTP connector.
ob_start();
require __DIR__ . '/seed-circularity-phase2.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
\Auth::login(\Vmod\Core\Models\User::findOrFail($fixture['a']['user_id']));
\Vmod\Core\Classes\Workspace::switchTo($fixture['a']['company_id'], null);
\Request::instance()->headers->set('X-Workspace-Context', \Vmod\Core\Classes\Workspace::token());
$client = new \Vmod\CircularityUi\Classes\CircularityClient;
$fixture['today'] = $client->request('GET', '/options')['today'];
$fixture['month_from'] = substr($fixture['today'], 0, 8) . '01';
$fixture['prior_date'] = \Carbon\Carbon::parse($fixture['month_from'])->subDay()->format('Y-m-d');
foreach (['main' => $fixture['a']['branch_id'], 'other' => $fixture['restricted_branch_id']] as $key => $branch) {
    $fixture['areas'][$key] = $client->request('POST', '/areas', [
        'name' => ($key === 'main' ? 'Shared kitchen ' : 'Other court ') . $fixture['nonce'], 'kind' => 'shared_area',
    ], (string) $branch)['area']['id'];
}
$fixture['entries'] = [];
foreach ([
    'food' => ['food_waste', '10.125', 'composting'],
    'unknown' => ['food_waste', '2.000', 'unknown'],
    'mixed' => ['mixed_waste', '3.250', 'unknown'],
    'packaging' => ['packaging_waste', '0', 'recycling'],
    'issued' => ['reusable_issued', '8', 'not_applicable'],
    'returned' => ['reusable_returned', '12', 'not_applicable'],
] as $key => [$type, $quantity, $destination]) {
    $entry = $client->request('POST', '/entries', [
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'area_id' => $fixture['areas']['main'],
        'measurement_date' => $fixture['today'], 'measurement_type' => $type, 'mode' => 'daily_total',
        'quantity' => $quantity, 'destination' => $destination,
    ], (string) $fixture['a']['branch_id'])['entry'];
    $fixture['entries'][$key] = $entry['uuid'];
}
// Historical individual readings prove all-history listing and pagination independently of the overview period.
for ($index = 0; $index < 26; $index++) {
    $client->request('POST', '/entries', [
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'area_id' => $fixture['areas']['main'],
        'measurement_date' => $fixture['prior_date'], 'measurement_type' => 'packaging_waste', 'mode' => 'individual',
        'quantity' => '0.001', 'destination' => 'recycling',
    ], (string) $fixture['a']['branch_id']);
}
$client->request('POST', '/entries', [
    'uuid' => (string) \Illuminate\Support\Str::uuid(), 'area_id' => $fixture['areas']['other'],
    'measurement_date' => $fixture['today'], 'measurement_type' => 'food_waste', 'mode' => 'daily_total',
    'quantity' => '100', 'destination' => 'unknown',
], (string) $fixture['restricted_branch_id']);
\Auth::logout();
echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
