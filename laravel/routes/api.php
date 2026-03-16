<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvatarController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminBackupController;
use App\Http\Controllers\Api\BlacklistController;
use App\Http\Controllers\Api\LegacyAuthController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\SocialBindingController;
use App\Http\Controllers\Api\AuthFeatureController;
use App\Http\Controllers\Api\SongCommentController;
use App\Http\Controllers\Api\SongController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserSecurityController;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [PublicController::class, 'healthz']);
Route::get('/site-config', [PublicController::class, 'siteConfig']);
Route::get('/play-times', [PublicController::class, 'playTimes']);
Route::get('/request-times', [PublicController::class, 'requestTimes']);
Route::get('/semesters/current', [PublicController::class, 'currentSemester']);
Route::get('/songs/count', [PublicController::class, 'songsCount']);
Route::get('/system/location', [PublicController::class, 'systemLocation']);
Route::get('/bootstrap/home', [PublicController::class, 'bootstrapHome']);
Route::post('/blacklist/check', [BlacklistController::class, 'check']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/login', [LegacyAuthController::class, 'loginGet']);
Route::get('/auth/verify', [AuthController::class, 'verify']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])
    ->where('provider', 'github|google|casdoor');
Route::get('/auth/{provider}', [OAuthController::class, 'redirectToProvider'])
    ->where('provider', 'github|google|casdoor');
Route::post('/auth/register', [AuthFeatureController::class, 'register']);
Route::get('/auth/register/activate', [LegacyAuthController::class, 'registerActivate']);
Route::get('/auth/register/verify', [LegacyAuthController::class, 'registerVerifyGet']);
Route::post('/auth/register/verify', [LegacyAuthController::class, 'registerVerifyPost']);
Route::post('/auth/register/resend-code', [AuthFeatureController::class, 'registerResendCode']);
Route::post('/auth/email-login/send-code', [AuthFeatureController::class, 'emailLoginSendCode']);
Route::post('/auth/email-login/verify', [AuthFeatureController::class, 'emailLoginVerify']);
Route::post('/auth/forgot-password/send-link', [AuthFeatureController::class, 'forgotPasswordSendLink']);
Route::post('/auth/forgot-password/reset', [AuthFeatureController::class, 'forgotPasswordReset']);
Route::post('/auth/webauthn/login/options', [AuthFeatureController::class, 'webauthnLoginOptions']);
Route::post('/auth/webauthn/login/verify', [AuthFeatureController::class, 'webauthnLoginVerify']);

Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '/api-enhanced/netease/{path}', [MediaController::class, 'neteaseEnhanced'])
    ->where('path', '.*');

Route::get('/bilibili/search', [MediaController::class, 'bilibiliSearch']);
Route::get('/bilibili/playurl', [MediaController::class, 'bilibiliPlayurl']);
Route::get('/native-api/search/tx', [MediaController::class, 'nativeSearchTx']);
Route::get('/native-api/search/wy', [MediaController::class, 'nativeSearchWy']);
Route::get('/proxy/image', [MediaController::class, 'proxyImage']);
Route::get('/music/proxy', [MediaController::class, 'musicProxyGet']);
Route::post('/music/proxy', [MediaController::class, 'musicProxyPost']);
Route::get('/music/file', [MediaController::class, 'musicFile']);
Route::post('/music/state', [RealtimeController::class, 'musicState']);
Route::get('/music/websocket', [RealtimeController::class, 'musicWebsocket']);
Route::get('/progress/id', [RealtimeController::class, 'progressId']);
Route::get('/progress/events', [RealtimeController::class, 'progressEvents']);
Route::post('/notifications/meow/send-verification', [SocialBindingController::class, 'meowSendVerification']);

Route::get('/user/avatar-file/{name}', [AvatarController::class, 'file'])->where('name', '[A-Za-z0-9._-]+');
Route::get('/songs/public', [SongController::class, 'publicSchedules']);
Route::get('/schedules/public', [SongController::class, 'publicSchedules']);
Route::get('/open/songs', [SongController::class, 'openSongs']);
Route::get('/open/schedules', [SongController::class, 'openSchedules']);
Route::get('/songs', [SongController::class, 'index']);
Route::get('/songs/{id}/voters', [SongController::class, 'voters'])->whereNumber('id');
Route::get('/songs/comments/list', [SongCommentController::class, 'list']);
Route::get('/songs/comments/counts', [SongCommentController::class, 'counts']);

Route::middleware('auth.jwt')->group(function (): void {
    Route::get('/semesters/options', [PublicController::class, 'semesterOptions']);

    Route::post('/songs/request', [SongController::class, 'requestSong']);
    Route::post('/songs/vote', [SongController::class, 'vote']);
    Route::post('/songs/withdraw', [SongController::class, 'withdraw']);
    Route::post('/songs/replay', [SongController::class, 'replay']);
    Route::delete('/songs/replay', [SongController::class, 'replay']);
    Route::get('/songs/submission-status', [SongController::class, 'submissionStatus']);
    Route::put('/songs/{id}/update', [SongController::class, 'updateSong'])->whereNumber('id');
    Route::post('/songs/add', [SongController::class, 'addSong']);
    Route::post('/songs/import', [SongController::class, 'importSongs']);
    Route::post('/songs/comments', [SongCommentController::class, 'create']);
    Route::post('/songs/comments/delete', [SongCommentController::class, 'delete']);
    Route::delete('/songs/comments/{id}', [SongCommentController::class, 'deleteById'])->whereNumber('id');
    Route::post('/songs/collaborators/reply', [SongController::class, 'collaboratorsReply']);

    Route::get('/user/profile', [UserController::class, 'profile']);
    Route::post('/user/profile', [UserController::class, 'updateProfile']);
    Route::put('/user/profile', [UserController::class, 'updateProfile']);
    Route::get('/user/year-review', [UserSecurityController::class, 'yearReview']);
    Route::post('/user/2fa/generate', [UserSecurityController::class, 'generate2fa']);
    Route::post('/user/2fa/enable', [UserSecurityController::class, 'enable2fa']);
    Route::post('/user/2fa/disable', [UserSecurityController::class, 'disable2fa']);
    Route::post('/user/avatar', [AvatarController::class, 'upload']);
    Route::delete('/user/avatar', [AvatarController::class, 'remove']);

    Route::get('/users/search', [UserController::class, 'search']);
    Route::get('/users/social-accounts', [UserController::class, 'socialAccounts']);
    Route::post('/users/social-accounts/meow', [SocialBindingController::class, 'socialAccountsMeowBind']);
    Route::delete('/users/social-accounts/meow', [SocialBindingController::class, 'socialAccountsMeowDelete']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/settings', [NotificationController::class, 'getSettings']);
    Route::post('/notifications/settings', [NotificationController::class, 'updateSettings']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->whereNumber('id');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/delete', [NotificationController::class, 'delete']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'deleteById'])->whereNumber('id');
    Route::post('/notifications/clear-all', [NotificationController::class, 'clearAll']);
    Route::delete('/notifications/clear-all', [NotificationController::class, 'clearAll']);
    Route::post('/notifications/meow/test', [SocialBindingController::class, 'meowTest']);

    Route::post('/meow/bind', [SocialBindingController::class, 'meowBind']);
    Route::post('/meow/unbind', [SocialBindingController::class, 'meowUnbind']);
    Route::post('/user/email/bind', [SocialBindingController::class, 'emailBind']);
    Route::post('/user/email/send-code', [SocialBindingController::class, 'emailSendCode']);
    Route::post('/user/email/verify-code', [SocialBindingController::class, 'emailVerifyCode']);
    Route::post('/user/email/resend-verification', [SocialBindingController::class, 'emailResendVerification']);
    Route::post('/user/email/unbind', [SocialBindingController::class, 'emailUnbind']);

    Route::post('/auth/change-password', [AuthFeatureController::class, 'changePassword']);
    Route::post('/auth/set-initial-password', [AuthFeatureController::class, 'setInitialPassword']);
    Route::post('/auth/2fa/send-email', [AuthFeatureController::class, 'send2faEmail']);
    Route::post('/auth/2fa/verify', [AuthFeatureController::class, 'verify2fa']);
    Route::get('/auth/identities', [AuthFeatureController::class, 'identities']);
    Route::post('/auth/bind', [AuthFeatureController::class, 'bind']);
    Route::post('/auth/unbind', [AuthFeatureController::class, 'unbind']);
    Route::get('/auth/webauthn/register/options', [AuthFeatureController::class, 'webauthnRegisterOptions']);
    Route::post('/auth/webauthn/register/verify', [AuthFeatureController::class, 'webauthnRegisterVerify']);
    Route::post('/auth/webauthn/rename', [AuthFeatureController::class, 'webauthnRename']);

    Route::get('/system/status', [SystemController::class, 'status']);
    Route::post('/system/reconnect', [SystemController::class, 'reconnect']);

    Route::get('/admin/stats', [AdminController::class, 'stats']);
    Route::get('/admin/stats/realtime', [AdminController::class, 'statsRealtime']);
    Route::get('/admin/stats/trends', [AdminController::class, 'statsTrends']);
    Route::get('/admin/stats/top-songs', [AdminController::class, 'statsTopSongs']);
    Route::get('/admin/stats/active-users', [AdminController::class, 'statsActiveUsers']);
    Route::get('/admin/stats/user-engagement', [AdminController::class, 'statsUserEngagement']);
    Route::get('/admin/stats/semester-comparison', [AdminController::class, 'statsSemesterComparison']);
    Route::get('/admin/activities', [AdminController::class, 'activities']);

    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::post('/admin/users', [AdminController::class, 'createUser']);
    Route::put('/admin/users/{id}', [AdminController::class, 'updateUser'])->whereNumber('id');
    Route::post('/admin/users/{id}/update', [AdminController::class, 'updateUser'])->whereNumber('id');
    Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser'])->whereNumber('id');
    Route::post('/admin/users/{id}/delete', [AdminController::class, 'deleteUser'])->whereNumber('id');
    Route::post('/admin/users/{id}/reset-password', [AdminController::class, 'resetUserPassword'])->whereNumber('id');
    Route::get('/admin/users/{id}/songs', [AdminController::class, 'userSongs'])->whereNumber('id');
    Route::get('/admin/users/{id}/status-logs', [AdminController::class, 'userStatusLogs'])->whereNumber('id');
    Route::put('/admin/users/{id}/status', [AdminController::class, 'updateUserStatus'])->whereNumber('id');
    Route::post('/admin/users/batch', [AdminController::class, 'batchUsers']);
    Route::post('/admin/users/batch-update', [AdminController::class, 'batchUpdateUsers']);
    Route::post('/admin/users/batch-grade-update', [AdminController::class, 'batchGradeUpdate']);
    Route::put('/admin/users/batch-status', [AdminController::class, 'batchStatus']);
    Route::get('/admin/users/status-logs', [AdminController::class, 'statusLogs']);

    Route::post('/admin/songs/delete', [AdminController::class, 'deleteSong']);
    Route::post('/admin/songs/mark-played', [AdminController::class, 'markPlayed']);
    Route::post('/admin/songs/reject', [AdminController::class, 'rejectSong']);
    Route::post('/admin/songs/{id}/vote-count', [AdminController::class, 'updateSongVoteCount'])->whereNumber('id');

    Route::get('/admin/system-settings', [AdminController::class, 'systemSettings']);
    Route::post('/admin/system-settings', [AdminController::class, 'updateSystemSettings']);
    Route::get('/admin/semesters', [AdminController::class, 'semesters']);
    Route::post('/admin/semesters', [AdminController::class, 'createSemester']);
    Route::post('/admin/semesters/set-active', [AdminController::class, 'setActiveSemester']);
    Route::delete('/admin/semesters/{id}', [AdminController::class, 'deleteSemester'])->whereNumber('id');

    Route::post('/admin/schedule', [AdminController::class, 'schedule']);
    Route::get('/admin/schedule/full', [AdminController::class, 'scheduleFull']);
    Route::post('/admin/schedule/remove', [AdminController::class, 'scheduleRemove']);
    Route::post('/admin/schedule/sequence', [AdminController::class, 'scheduleSequence']);
    Route::post('/admin/schedule/draft', [AdminController::class, 'scheduleDraft']);
    Route::post('/admin/schedule/publish', [AdminController::class, 'schedulePublish']);
    Route::post('/admin/schedule/bulk-publish', [AdminController::class, 'scheduleBulkPublish']);

    Route::get('/admin/replay-requests', [AdminController::class, 'replayRequests']);
    Route::post('/admin/replay-requests/reject', [AdminController::class, 'rejectReplayRequest']);

    Route::post('/admin/notifications/send', [AdminController::class, 'sendNotification']);

    Route::get('/admin/blacklist', [AdminController::class, 'blacklistList']);
    Route::post('/admin/blacklist', [AdminController::class, 'blacklistCreate']);
    Route::put('/admin/blacklist/{id}', [AdminController::class, 'blacklistUpdate'])->whereNumber('id');
    Route::delete('/admin/blacklist/{id}', [AdminController::class, 'blacklistDelete'])->whereNumber('id');

    Route::post('/admin/smtp/reload', [AdminController::class, 'smtpReload']);
    Route::post('/admin/smtp/test-connection', [AdminController::class, 'smtpTestConnection']);
    Route::post('/admin/smtp/test-email', [AdminController::class, 'smtpTestEmail']);

    Route::get('/admin/email-templates', [AdminController::class, 'emailTemplates']);
    Route::post('/admin/email-templates', [AdminController::class, 'emailTemplateSave']);
    Route::delete('/admin/email-templates', [AdminController::class, 'emailTemplateDelete']);
    Route::post('/admin/email-templates/preview', [AdminController::class, 'emailTemplatePreview']);

    Route::post('/admin/backup/export', [AdminController::class, 'backupExport']);
    Route::get('/admin/backup/list', [AdminBackupController::class, 'list']);
    Route::post('/admin/backup/upload', [AdminBackupController::class, 'upload']);
    Route::get('/admin/backup/download', [AdminBackupController::class, 'download']);
    Route::get('/admin/backup/download/{filename}', [AdminBackupController::class, 'downloadByFilename'])->where('filename', '[A-Za-z0-9._-]+');
    Route::delete('/admin/backup/delete/{filename}', [AdminBackupController::class, 'delete'])->where('filename', '[A-Za-z0-9._-]+');
    Route::post('/admin/backup/restore', [AdminController::class, 'backupRestore']);
    Route::post('/admin/backup/restore-chunk', [AdminController::class, 'backupRestoreChunk']);
    Route::post('/admin/backup/clear', [AdminController::class, 'backupClear']);
    Route::get('/admin/db-status', [SystemController::class, 'dbStatus']);
    Route::post('/admin/fix-sequence', [AdminController::class, 'fixSequence']);
    Route::post('/admin/database/reset', [AdminController::class, 'databaseReset']);
});
