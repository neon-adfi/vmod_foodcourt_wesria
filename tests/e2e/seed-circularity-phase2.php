<?php

// Reuse the guarded additive identity seed; change only this newly created synthetic reader role.
ob_start();
require __DIR__ . '/seed-phase2.php';
$fixture = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
$role = \Vmod\Core\Models\Role::where('company_id', $fixture['a']['company_id'])->findOrFail($fixture['reader_role_id']);
$role->permissions = ['circularity.read'];
$role->save();
echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
