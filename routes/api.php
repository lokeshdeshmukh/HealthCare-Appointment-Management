<?php

declare(strict_types=1);

use App\Controllers\Api\AppointmentApiController;
use App\Controllers\Api\DoctorApiController;
use App\Controllers\Api\SmsBridgeApiController;

$router->get('/api/v1/clinics', [DoctorApiController::class, 'clinics']);
$router->get('/api/v1/clinics/{slug}/doctors', [DoctorApiController::class, 'clinicDoctors']);
$router->get('/api/v1/doctors/{id}', [DoctorApiController::class, 'show']);
$router->get('/api/v1/doctors/{id}/slots', [DoctorApiController::class, 'slots']);
$router->get('/api/v1/admin/appointments', [AppointmentApiController::class, 'adminIndex'], ['auth:clinic']);
$router->post('/api/v1/appointments', [AppointmentApiController::class, 'store'], ['auth:patient', 'csrf']);
$router->post('/api/v1/appointments/{id}/cancel', [AppointmentApiController::class, 'cancel'], ['csrf']);
$router->get('/api/pending-sms', [SmsBridgeApiController::class, 'pending']);
$router->post('/api/sms-status', [SmsBridgeApiController::class, 'status']);
