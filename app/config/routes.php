<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 */


/** @var object $router */


// ==========================================================
// HOME
// ==========================================================

$router->get('/', 'FrontendController::home');


// ==========================================================
// LAB 6 - API AUTHENTICATION
// ==========================================================

// OPTIONS - CORS Preflight
$router->options('/api/login', 'ApiAuthController::login');

// GET - Return a JSON method error for unsupported login requests
$router->get('/api/login', 'ApiAuthController::login');

// POST - API Login
$router->post('/api/login', 'ApiAuthController::login');
$router->get('/api/auth/google', 'ApiAuthController::google_start');
$router->get('/api/auth/google/callback', 'ApiAuthController::google_callback');
$router->options('/api/register/parent', 'ApiAuthController::register_parent');
$router->post('/api/register/parent', 'ApiAuthController::register_parent');
$router->options('/api/register/teacher', 'ApiAuthController::register_teacher');
$router->post('/api/register/teacher', 'ApiAuthController::register_teacher');
$router->options('/api/me', 'ApiAuthController::me');
$router->get('/api/me', 'ApiAuthController::me');
$router->options('/api/parent/profile', 'ParentProfileApiController::update');
$router->patch('/api/parent/profile', 'ParentProfileApiController::update');
$router->options('/api/parent/dashboard', 'ParentDashboardApiController::index');
$router->get('/api/parent/dashboard', 'ParentDashboardApiController::index');
$router->options('/api/parent/children', 'ParentChildrenApiController::index');
$router->get('/api/parent/children', 'ParentChildrenApiController::index');
$router->options('/api/parent/children/{id}', 'ParentChildrenApiController::update');
$router->put('/api/parent/children/{id}', 'ParentChildrenApiController::update');
$router->options('/api/parent/progress', 'ParentProgressApiController::index');
$router->get('/api/parent/progress', 'ParentProgressApiController::index');
$router->options('/api/parent/activities', 'ParentActivitiesApiController::index');
$router->get('/api/parent/activities', 'ParentActivitiesApiController::index');
$router->options('/api/settings', 'SettingsApiController::index');
$router->get('/api/settings', 'SettingsApiController::index');
$router->put('/api/settings', 'SettingsApiController::save');
$router->options('/api/settings/profile-photo', 'SettingsApiController::profile_photo');
$router->get('/api/settings/profile-photo', 'SettingsApiController::profile_photo');
$router->post('/api/settings/profile-photo', 'SettingsApiController::upload_profile_photo');

// OPTIONS - Token refresh and logout preflight
$router->options('/api/refresh', 'ApiAuthController::refresh');
$router->options('/api/logout', 'ApiAuthController::logout');

// GET - Return JSON method errors for unsupported token requests
$router->get('/api/refresh', 'ApiAuthController::refresh');
$router->get('/api/logout', 'ApiAuthController::logout');
$router->options('/api/cdcs', 'EnrollmentApiController::cdcs');
$router->get('/api/cdcs', 'EnrollmentApiController::cdcs');
$router->options('/api/attendance', 'AttendanceApiController::index');
$router->get('/api/attendance', 'AttendanceApiController::index');
$router->post('/api/attendance', 'AttendanceApiController::save');
$router->options('/api/teacher/dashboard', 'TeacherDashboardApiController::index');
$router->get('/api/teacher/dashboard', 'TeacherDashboardApiController::index');
$router->options('/api/teacher/students', 'TeacherStudentsApiController::index');
$router->get('/api/teacher/students', 'TeacherStudentsApiController::index');
$router->options('/api/teacher/children-progress', 'TeacherChildrenProgressApiController::index');
$router->get('/api/teacher/children-progress', 'TeacherChildrenProgressApiController::index');
$router->post('/api/teacher/children-progress', 'TeacherChildrenProgressApiController::create');
$router->options('/api/teacher/learning-activities', 'TeacherLearningActivitiesApiController::index');
$router->get('/api/teacher/learning-activities', 'TeacherLearningActivitiesApiController::index');
$router->post('/api/teacher/learning-activities', 'TeacherLearningActivitiesApiController::create');
$router->options('/api/teacher/learning-activities/{id}', 'TeacherLearningActivitiesApiController::update');
$router->put('/api/teacher/learning-activities/{id}', 'TeacherLearningActivitiesApiController::update');
$router->options('/api/teacher/lesson-plans', 'TeacherLessonPlansApiController::index');
$router->get('/api/teacher/lesson-plans', 'TeacherLessonPlansApiController::index');
$router->post('/api/teacher/lesson-plans', 'TeacherLessonPlansApiController::create');
$router->options('/api/teacher/lesson-plans/{id}', 'TeacherLessonPlansApiController::update');
$router->put('/api/teacher/lesson-plans/{id}', 'TeacherLessonPlansApiController::update');
$router->options('/api/teacher/lesson-plans/{id}/favorite', 'TeacherLessonPlansApiController::favorite');
$router->patch('/api/teacher/lesson-plans/{id}/favorite', 'TeacherLessonPlansApiController::favorite');
$router->options('/api/teacher/lesson-plans/{id}/completion', 'TeacherLessonPlansApiController::completion');
$router->patch('/api/teacher/lesson-plans/{id}/completion', 'TeacherLessonPlansApiController::completion');
$router->options('/api/teacher/tasks', 'TeacherTasksApiController::index');
$router->get('/api/teacher/tasks', 'TeacherTasksApiController::index');
$router->post('/api/teacher/tasks', 'TeacherTasksApiController::create');
$router->options('/api/teacher/tasks/{id}/submit', 'TeacherTasksApiController::submit');
$router->post('/api/teacher/tasks/{id}/submit', 'TeacherTasksApiController::submit');
$router->options('/api/teacher/notifications', 'TeacherNotificationsApiController::index');
$router->get('/api/teacher/notifications', 'TeacherNotificationsApiController::index');
$router->options('/api/teacher/notifications/count', 'TeacherNotificationsApiController::count');
$router->get('/api/teacher/notifications/count', 'TeacherNotificationsApiController::count');
$router->options('/api/teacher/notifications/read', 'TeacherNotificationsApiController::update_read');
$router->patch('/api/teacher/notifications/read', 'TeacherNotificationsApiController::update_read');
$router->options('/api/teacher/notifications/read-all', 'TeacherNotificationsApiController::mark_all_read');
$router->patch('/api/teacher/notifications/read-all', 'TeacherNotificationsApiController::mark_all_read');
$router->options('/api/teacher/profile', 'TeacherProfileApiController::index');
$router->get('/api/teacher/profile', 'TeacherProfileApiController::index');
$router->put('/api/teacher/profile', 'TeacherProfileApiController::update');
$router->options('/api/teacher/verification', 'TeacherVerificationApiController::index');
$router->get('/api/teacher/verification', 'TeacherVerificationApiController::index');
$router->patch('/api/teacher/verification', 'TeacherVerificationApiController::save_draft');
$router->put('/api/teacher/verification', 'TeacherVerificationApiController::update');
$router->options('/api/admin/dashboard', 'AdminDashboardApiController::index');
$router->get('/api/admin/dashboard', 'AdminDashboardApiController::index');
$router->options('/api/admin/centers', 'AdminCentersApiController::index');
$router->get('/api/admin/centers', 'AdminCentersApiController::index');
$router->post('/api/admin/centers', 'AdminCentersApiController::create');
$router->options('/api/admin/centers/{id}', 'AdminCentersApiController::update');
$router->put('/api/admin/centers/{id}', 'AdminCentersApiController::update');
$router->options('/api/admin/centers/{id}/status', 'AdminCentersApiController::update_status');
$router->patch('/api/admin/centers/{id}/status', 'AdminCentersApiController::update_status');
$router->options('/api/admin/centers/{id}/teacher', 'AdminCentersApiController::assign_teacher');
$router->patch('/api/admin/centers/{id}/teacher', 'AdminCentersApiController::assign_teacher');
$router->options('/api/admin/centers/{id}/affected-enrollments', 'AdminCentersApiController::affected_enrollments');
$router->get('/api/admin/centers/{id}/affected-enrollments', 'AdminCentersApiController::affected_enrollments');
$router->options('/api/admin/centers/{id}/transfer-history', 'AdminCentersApiController::transfer_history');
$router->get('/api/admin/centers/{id}/transfer-history', 'AdminCentersApiController::transfer_history');
$router->options('/api/admin/enrollment-applications/{id}/transfer', 'AdminCentersApiController::transfer_application');
$router->post('/api/admin/enrollment-applications/{id}/transfer', 'AdminCentersApiController::transfer_application');
$router->options('/api/admin/enrollment-overview', 'AdminEnrollmentOverviewApiController::index');
$router->get('/api/admin/enrollment-overview', 'AdminEnrollmentOverviewApiController::index');
$router->options('/api/admin/children', 'AdminChildrenApiController::index');
$router->get('/api/admin/children', 'AdminChildrenApiController::index');
$router->options('/api/admin/children/{id}', 'AdminChildrenApiController::show');
$router->get('/api/admin/children/{id}', 'AdminChildrenApiController::show');
$router->options('/api/admin/enrollment-applications/{id}', 'AdminChildrenApiController::show_application');
$router->get('/api/admin/enrollment-applications/{id}', 'AdminChildrenApiController::show_application');
$router->options('/api/admin/teachers', 'AdminTeachersApiController::index');
$router->get('/api/admin/teachers', 'AdminTeachersApiController::index');
$router->options('/api/admin/teachers/{id}', 'AdminTeachersApiController::show');
$router->get('/api/admin/teachers/{id}', 'AdminTeachersApiController::show');
$router->options('/api/admin/teachers/{id}/documents/{document_id}', 'AdminTeachersApiController::download_document');
$router->get('/api/admin/teachers/{id}/documents/{document_id}', 'AdminTeachersApiController::download_document');
$router->options('/api/admin/teachers/{id}/review', 'AdminTeachersApiController::review');
$router->patch('/api/admin/teachers/{id}/review', 'AdminTeachersApiController::review');
$router->options('/api/admin/teachers/{id}/message', 'AdminTeachersApiController::send_message');
$router->post('/api/admin/teachers/{id}/message', 'AdminTeachersApiController::send_message');
$router->options('/api/admin/users', 'AdminUsersApiController::index');
$router->get('/api/admin/users', 'AdminUsersApiController::index');
$router->post('/api/admin/users', 'AdminUsersApiController::create');
$router->options('/api/admin/users/{id}', 'AdminUsersApiController::show');
$router->get('/api/admin/users/{id}', 'AdminUsersApiController::show');
$router->put('/api/admin/users/{id}', 'AdminUsersApiController::update');
$router->options('/api/admin/users/{id}/status', 'AdminUsersApiController::update_status');
$router->patch('/api/admin/users/{id}/status', 'AdminUsersApiController::update_status');
$router->delete('/api/admin/users/{id}', 'AdminUsersApiController::archive');
$router->options('/api/admin/learning-development', 'AdminLearningDevelopmentApiController::index');
$router->get('/api/admin/learning-development', 'AdminLearningDevelopmentApiController::index');
$router->options('/api/admin/reports', 'AdminReportsApiController::index');
$router->get('/api/admin/reports', 'AdminReportsApiController::index');
$router->options('/api/enrollments', 'EnrollmentApiController::index');
$router->get('/api/enrollments', 'EnrollmentApiController::index');
$router->options('/api/admin/teacher-assignment-options', 'EnrollmentApiController::assignment_options');
$router->get('/api/admin/teacher-assignment-options', 'EnrollmentApiController::assignment_options');
$router->options('/api/enrollment-notifications', 'EnrollmentApiController::notifications');
$router->get('/api/enrollment-notifications', 'EnrollmentApiController::notifications');
$router->options('/api/enrollment-notifications/read', 'EnrollmentApiController::mark_notifications_read');
$router->patch('/api/enrollment-notifications/read', 'EnrollmentApiController::mark_notifications_read');
$router->post('/api/enrollments', 'EnrollmentApiController::submit');
$router->options('/api/enrollments/{id}/documents', 'EnrollmentApiController::upload_additional_documents');
$router->post('/api/enrollments/{id}/documents', 'EnrollmentApiController::upload_additional_documents');
$router->options('/api/enrollments/{id}/status', 'EnrollmentApiController::update_status');
$router->patch('/api/enrollments/{id}/status', 'EnrollmentApiController::update_status');
$router->options('/api/enrollments/{id}/assignment', 'EnrollmentApiController::assign_teacher');
$router->patch('/api/enrollments/{id}/assignment', 'EnrollmentApiController::assign_teacher');
$router->get('/api/enrollments/{id}/documents/{document_id}', 'EnrollmentApiController::download_document');
$router->options('/api/announcements', 'AnnouncementApiController::index');
$router->get('/api/announcements', 'AnnouncementApiController::index');
$router->post('/api/announcements', 'AnnouncementApiController::create');
$router->options('/api/announcements/{id}', 'AnnouncementApiController::update');
$router->post('/api/announcements/{id}', 'AnnouncementApiController::update');
$router->delete('/api/announcements/{id}', 'AnnouncementApiController::delete');
$router->get('/api/announcements/{id}/attachment', 'AnnouncementApiController::download');
$router->options('/api/events', 'EventsApiController::index');
$router->get('/api/events', 'EventsApiController::index');
$router->post('/api/events', 'EventsApiController::create');
$router->options('/api/events/notifications', 'EventsApiController::notifications');
$router->get('/api/events/notifications', 'EventsApiController::notifications');
$router->options('/api/events/notifications/count', 'EventsApiController::notification_count');
$router->get('/api/events/notifications/count', 'EventsApiController::notification_count');
$router->options('/api/events/notifications/read', 'EventsApiController::update_notification_read');
$router->patch('/api/events/notifications/read', 'EventsApiController::update_notification_read');
$router->options('/api/events/notifications/read-all', 'EventsApiController::mark_event_notifications_read');
$router->patch('/api/events/notifications/read-all', 'EventsApiController::mark_event_notifications_read');
$router->options('/api/notifications', 'NotificationsApiController::index');
$router->get('/api/notifications', 'NotificationsApiController::index');
$router->post('/api/notifications', 'NotificationsApiController::create');
$router->options('/api/notifications/recipients', 'NotificationsApiController::recipient_options');
$router->get('/api/notifications/recipients', 'NotificationsApiController::recipient_options');
$router->options('/api/notifications/preview', 'NotificationsApiController::preview_recipients');
$router->post('/api/notifications/preview', 'NotificationsApiController::preview_recipients');
$router->options('/api/notifications/count', 'NotificationsApiController::count');
$router->get('/api/notifications/count', 'NotificationsApiController::count');
$router->options('/api/notifications/read', 'NotificationsApiController::update_read');
$router->patch('/api/notifications/read', 'NotificationsApiController::update_read');
$router->options('/api/notifications/read-all', 'NotificationsApiController::mark_all_read');
$router->patch('/api/notifications/read-all', 'NotificationsApiController::mark_all_read');
$router->options('/api/events/{id}', 'EventsApiController::show');
$router->get('/api/events/{id}', 'EventsApiController::show');
$router->put('/api/events/{id}', 'EventsApiController::update');
$router->delete('/api/events/{id}', 'EventsApiController::delete');
$router->options('/api/events/{id}/album', 'EventsApiController::create_album');
$router->post('/api/events/{id}/album', 'EventsApiController::create_album');
$router->options('/api/events/{id}/photos', 'EventsApiController::upload_photos');
$router->post('/api/events/{id}/photos', 'EventsApiController::upload_photos');
$router->options('/api/events/photos/{id}', 'EventsApiController::update_photo');
$router->patch('/api/events/photos/{id}', 'EventsApiController::update_photo');
$router->delete('/api/events/photos/{id}', 'EventsApiController::delete_photo');
$router->options('/api/events/photos/{id}/image', 'EventsApiController::image');
$router->get('/api/events/photos/{id}/image', 'EventsApiController::image');

// POST - Rotate refresh and access tokens
$router->post('/api/refresh', 'ApiAuthController::refresh');

// POST - Revoke refresh token
$router->post('/api/logout', 'ApiAuthController::logout');


// ==========================================================
// KALINGALINK FRONTEND
// ==========================================================

$frontend_paths = [
    '/',
    '/get-started',
    '/signup',
    '/signup/parent',
    '/signup/teacher',
    '/login',
    '/admin',
    '/admin/dashboard',
    '/admin/events',
    '/admin/centers',
    '/admin/enrollment',
    '/admin/children',
    '/admin/attendance',
    '/admin/learning-development',
    '/admin/reports',
    '/admin/notifications',
    '/admin/settings',
    '/admin/teachers',
    '/admin/teachers/add',
    '/teacher/dashboard',
    '/teacher/events',
    '/teacher/enrollment',
    '/teacher/students',
    '/teacher/attendance',
    '/teacher/learning-activities',
    '/teacher/lesson-plans',
    '/teacher/children-progress',
    '/teacher/tasks',
    '/teacher/notifications',
    '/teacher/profile',
    '/parent',
    '/parent/dashboard',
    '/parent/events',
    '/parent/notifications',
    '/parent/activities',
    '/parent/enrollment',
    '/parent/application-status',
    '/parent/children',
    '/parent/attendance',
    '/parent/progress',
    '/parent/announcements',
    '/parent/profile',
];

foreach ($frontend_paths as $frontend_path) {
    $router->get('/app' . $frontend_path, 'FrontendController::index');
}

if (defined('IS_CLI') && IS_CLI) {
    $router->get('/cli/migrate', 'MigrationController::run');
    $router->get('/cli/seed', 'SeederController::run');
}


// ==========================================================
// END OF ROUTES
// ==========================================================

?>