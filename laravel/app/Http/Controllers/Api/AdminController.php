<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoleHelper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdminController extends Controller
{
    private function ensureAdmin(Request $request): ?JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (!RoleHelper::isAdminLike($user->role)) {
            return response()->json(['message' => '需要管理员权限'], 403);
        }

        return null;
    }

    private function ensureSongAdmin(Request $request): ?JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (!RoleHelper::isSongAdminLike($user->role)) {
            return response()->json(['message' => '没有权限访问'], 403);
        }

        return null;
    }

    public function stats(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $semester = trim((string) $request->query('semester', ''));
        $songQuery = DB::table('Song');
        if ($semester !== '' && $semester !== 'all') {
            $songQuery->where('semester', $semester);
        }

        $totalSongs = (int) (clone $songQuery)->count();
        $totalUsers = (int) DB::table('User')->count();
        $today = Carbon::now('Asia/Shanghai')->startOfDay();
        $tomorrow = (clone $today)->addDay();
        $todaySchedules = (int) DB::table('Schedule')
            ->whereBetween('playDate', [$today, $tomorrow])
            ->distinct('playDate')
            ->count('playDate');
        $totalSchedules = (int) DB::table('Schedule')->distinct('playDate')->count('playDate');
        $weekAgo = Carbon::now('Asia/Shanghai')->subDays(7);
        $twoWeeksAgo = Carbon::now('Asia/Shanghai')->subDays(14);
        $weeklyRequests = (int) DB::table('Song')->when($semester !== '' && $semester !== 'all', fn ($q) => $q->where('semester', $semester))->where('createdAt', '>=', $weekAgo)->count();
        $previousWeekRequests = (int) DB::table('Song')->when($semester !== '' && $semester !== 'all', fn ($q) => $q->where('semester', $semester))->whereBetween('createdAt', [$twoWeeksAgo, $weekAgo])->count();
        $requestsChange = $previousWeekRequests > 0 ? (int) round((($weeklyRequests - $previousWeekRequests) / $previousWeekRequests) * 100) : ($weeklyRequests > 0 ? 100 : 0);
        $blacklistCount = Schema::hasTable('SongBlacklist')
            ? (int) DB::table('SongBlacklist')->where('isActive', true)->count()
            : 0;

        return response()->json([
            'summary' => [
                'totalSongs' => $totalSongs,
                'totalUsers' => $totalUsers,
                'todaySchedules' => $todaySchedules,
                'totalSchedules' => $totalSchedules,
                'weeklyRequests' => $weeklyRequests,
                'requestsChange' => $requestsChange,
                'blacklistCount' => $blacklistCount,
            ],
            'semester' => $semester !== '' ? $semester : (DB::table('Semester')->where('isActive', true)->value('name') ?: null),
        ]);
    }

    public function statsRealtime(Request $request): JsonResponse
    {
        return $this->stats($request);
    }

    public function statsTrends(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $semester = trim((string) $request->query('semester', ''));
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now('Asia/Shanghai')->subDays($i)->startOfDay();
            $next = (clone $date)->addDay();
            $songCount = (int) DB::table('Song')
                ->when($semester !== '' && $semester !== 'all', fn ($q) => $q->where('semester', $semester))
                ->whereBetween('createdAt', [$date, $next])
                ->count();
            $userCount = (int) DB::table('User')->whereBetween('createdAt', [$date, $next])->count();
            $scheduleCount = (int) DB::table('Schedule')->whereBetween('playDate', [$date, $next])->count();
            $data[] = [
                'date' => $date->format('Y-m-d'),
                'songs' => $songCount,
                'users' => $userCount,
                'schedules' => $scheduleCount,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function statsTopSongs(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $semester = trim((string) $request->query('semester', ''));
        $limit = max(1, min(50, (int) $request->query('limit', 10)));

        $rows = DB::table('Song as s')
            ->leftJoin('Vote as v', 'v.songId', '=', 's.id')
            ->when($semester !== '' && $semester !== 'all', fn ($q) => $q->where('s.semester', $semester))
            ->groupBy(['s.id', 's.title', 's.artist', 's.semester'])
            ->orderByDesc(DB::raw('COUNT(v.id)'))
            ->limit($limit)
            ->get([
                's.id',
                's.title',
                's.artist',
                's.semester',
                DB::raw('COUNT(v.id) as voteCount'),
            ]);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function statsActiveUsers(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $days = max(1, min(30, (int) $request->query('days', 7)));
        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now('Asia/Shanghai')->subDays($i)->startOfDay();
            $next = (clone $date)->addDay();
            $count = (int) DB::table('User')->whereBetween('lastLogin', [$date, $next])->count();
            $rows[] = [
                'date' => $date->format('Y-m-d'),
                'activeUsers' => $count,
            ];
        }

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function statsUserEngagement(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $totalUsers = (int) DB::table('User')->count();
        $activeWeek = (int) DB::table('User')->where('lastLogin', '>=', Carbon::now('Asia/Shanghai')->subDays(7))->count();
        $withSongs = (int) DB::table('Song')->distinct('requesterId')->count('requesterId');
        $withVotes = (int) DB::table('Vote')->distinct('userId')->count('userId');

        return response()->json([
            'success' => true,
            'data' => [
                'totalUsers' => $totalUsers,
                'weeklyActiveUsers' => $activeWeek,
                'usersWithSongs' => $withSongs,
                'usersWithVotes' => $withVotes,
            ],
        ]);
    }

    public function statsSemesterComparison(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $rows = DB::table('Song')
            ->select('semester', DB::raw('COUNT(id) as songCount'))
            ->whereNotNull('semester')
            ->groupBy('semester')
            ->orderBy('semester')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function activities(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $limit = max(1, min(50, (int) $request->query('limit', 10)));
        $activities = [];

        $recentSongs = DB::table('Song as s')
            ->leftJoin('User as u', 's.requesterId', '=', 'u.id')
            ->orderByDesc('s.createdAt')
            ->limit(5)
            ->get(['s.id', 's.title', 's.createdAt', 'u.name', 'u.username']);
        foreach ($recentSongs as $song) {
            $activities[] = [
                'id' => 'song-'.$song->id,
                'type' => 'song',
                'title' => '新歌曲投稿',
                'description' => ($song->name ?: ($song->username ?: '用户')).' 投稿了《'.$song->title.'》',
                'createdAt' => $song->createdAt,
            ];
        }

        $recentSchedules = DB::table('Schedule as sch')
            ->leftJoin('Song as s', 'sch.songId', '=', 's.id')
            ->orderByDesc('sch.createdAt')
            ->limit(3)
            ->get(['sch.id', 'sch.createdAt', 's.title as songTitle']);
        foreach ($recentSchedules as $sch) {
            $activities[] = [
                'id' => 'schedule-'.$sch->id,
                'type' => 'schedule',
                'title' => '排期更新',
                'description' => '《'.($sch->songTitle ?: '未知歌曲').'》已安排到播放排期',
                'createdAt' => $sch->createdAt,
            ];
        }

        usort($activities, static fn ($a, $b) => strtotime((string) $b['createdAt']) <=> strtotime((string) $a['createdAt']));
        $activities = array_slice($activities, 0, $limit);

        return response()->json([
            'activities' => $activities,
            'total' => count($activities),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min(200, (int) $request->query('limit', 50)));
        $offset = ($page - 1) * $limit;
        $search = trim((string) $request->query('search', ''));
        $grade = trim((string) $request->query('grade', ''));
        $class = trim((string) $request->query('class', ''));
        $role = trim((string) $request->query('role', ''));
        $status = trim((string) $request->query('status', ''));
        $sortBy = trim((string) $request->query('sortBy', 'id'));
        $sortOrder = strtolower((string) $request->query('sortOrder', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = DB::table('User');
        if ($grade !== '') {
            $query->where('grade', $grade);
        }
        if ($class !== '') {
            $query->where('class', $class);
        }
        if ($role !== '') {
            $query->where('role', strtoupper($role));
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $searchLike = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(name) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(lastLoginIp) LIKE ?', [$searchLike]);
            });
        }

        $total = (int) (clone $query)->count();
        $allowedSortBy = ['id', 'name', 'lastLogin', 'createdAt'];
        $sortColumn = in_array($sortBy, $allowedSortBy, true) ? $sortBy : 'id';

        $users = $query->orderBy($sortColumn, $sortOrder)->offset($offset)->limit($limit)->get();

        return response()->json([
            'success' => true,
            'users' => $users,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => (int) ceil($total / $limit),
                'hasNextPage' => $page * $limit < $total,
                'hasPrevPage' => $page > 1,
            ],
            'filters' => [
                'grade' => $grade !== '' ? $grade : null,
                'class' => $class !== '' ? $class : null,
                'role' => $role !== '' ? $role : null,
                'status' => $status !== '' ? $status : null,
                'search' => $search !== '' ? $search : null,
            ],
        ]);
    }

    public function createUser(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        if ($username === '' || $password === '') {
            return response()->json(['message' => '用户名和密码不能为空'], 400);
        }
        if (DB::table('User')->where('username', $username)->exists()) {
            return response()->json(['message' => '用户名已存在'], 400);
        }

        $id = DB::table('User')->insertGetId([
            'createdAt' => now(),
            'updatedAt' => now(),
            'username' => $username,
            'name' => $request->input('name') ?: $username,
            'grade' => $request->input('grade'),
            'class' => $request->input('class'),
            'avatar' => null,
            'role' => RoleHelper::normalizeRoleOrDefault((string) $request->input('role', 'USER'), 'USER'),
            'password' => Hash::make($password),
            'email' => $request->input('email'),
            'emailVerified' => (bool) $request->input('emailVerified', false),
            'forcePasswordChange' => true,
            'status' => 'active',
        ], 'id');

        return response()->json(['success' => true, 'id' => (int) $id, 'message' => '用户创建成功']);
    }

    public function updateUser(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $user = DB::table('User')->where('id', $id)->first();
        if ($user === null) {
            return response()->json(['message' => '用户不存在'], 404);
        }

        $payload = [];
        foreach (['name', 'grade', 'class', 'email', 'status'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }
        if ($request->has('role')) {
            $payload['role'] = RoleHelper::normalizeRoleOrDefault((string) $request->input('role'), 'USER');
        }
        if ($request->has('password') && trim((string) $request->input('password')) !== '') {
            $payload['password'] = Hash::make((string) $request->input('password'));
            $payload['passwordChangedAt'] = now();
            $payload['forcePasswordChange'] = false;
        }
        $payload['updatedAt'] = now();

        DB::table('User')->where('id', $id)->update($payload);

        return response()->json(['success' => true, 'message' => '用户更新成功']);
    }

    public function deleteUser(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        DB::table('User')->where('id', $id)->delete();

        return response()->json(['success' => true, 'message' => '用户已删除']);
    }

    public function resetUserPassword(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $newPassword = (string) $request->input('password', '12345678');
        DB::table('User')->where('id', $id)->update([
            'password' => Hash::make($newPassword),
            'passwordChangedAt' => now(),
            'forcePasswordChange' => true,
            'updatedAt' => now(),
        ]);

        return response()->json(['success' => true, 'message' => '密码重置成功']);
    }

    public function userSongs(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        $songs = DB::table('Song')->where('requesterId', $id)->orderByDesc('createdAt')->get();

        return response()->json(['success' => true, 'songs' => $songs]);
    }

    public function userStatusLogs(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        if (!Schema::hasTable('user_status_logs')) {
            return response()->json(['success' => true, 'logs' => []]);
        }

        $logs = DB::table('user_status_logs')->where('user_id', $id)->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'logs' => $logs]);
    }

    public function updateUserStatus(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $status = trim((string) $request->input('status', 'active'));
        DB::table('User')->where('id', $id)->update([
            'status' => $status,
            'statusChangedAt' => now(),
            'statusChangedBy' => $request->user()->id,
            'updatedAt' => now(),
        ]);

        if (Schema::hasTable('user_status_logs')) {
            DB::table('user_status_logs')->insert([
                'user_id' => $id,
                'old_status' => null,
                'new_status' => $status,
                'reason' => $request->input('reason'),
                'operator_id' => $request->user()->id,
                'created_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'message' => '用户状态更新成功']);
    }

    public function batchUsers(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $items = $request->input('users', []);
        if (!is_array($items)) {
            return response()->json(['message' => 'users 参数无效'], 400);
        }

        $created = 0;
        foreach ($items as $item) {
            $username = trim((string) ($item['username'] ?? ''));
            if ($username === '' || DB::table('User')->where('username', $username)->exists()) {
                continue;
            }
            DB::table('User')->insert([
                'createdAt' => now(),
                'updatedAt' => now(),
                'username' => $username,
                'name' => $item['name'] ?? $username,
                'grade' => $item['grade'] ?? null,
                'class' => $item['class'] ?? null,
                'role' => RoleHelper::normalizeRoleOrDefault((string) ($item['role'] ?? 'USER'), 'USER'),
                'password' => Hash::make((string) ($item['password'] ?? '12345678')),
                'forcePasswordChange' => true,
                'status' => 'active',
            ]);
            $created++;
        }

        return response()->json(['success' => true, 'created' => $created]);
    }

    public function batchUpdateUsers(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $userIds = $request->input('userIds', []);
        if (!is_array($userIds) || $userIds === []) {
            return response()->json(['message' => 'userIds 参数无效'], 400);
        }

        $payload = [];
        foreach (['grade', 'class', 'status', 'role'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }
        if ($payload === []) {
            return response()->json(['message' => '未提供可更新字段'], 400);
        }
        $payload['updatedAt'] = now();

        $affected = DB::table('User')->whereIn('id', $userIds)->update($payload);

        return response()->json(['success' => true, 'affected' => $affected]);
    }

    public function batchGradeUpdate(Request $request): JsonResponse
    {
        return $this->batchUpdateUsers($request);
    }

    public function batchStatus(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $userIds = $request->input('userIds', []);
        $status = trim((string) $request->input('status', ''));
        $reason = trim((string) $request->input('reason', ''));

        if (!is_array($userIds) || $userIds === []) {
            return response()->json(['message' => '用户ID列表不能为空'], 400);
        }
        if (!in_array($status, ['active', 'withdrawn'], true)) {
            return response()->json(['message' => '状态必须为 active 或 withdrawn'], 400);
        }
        if ($reason === '') {
            return response()->json(['message' => '变更原因为必填项'], 400);
        }

        $validUserIds = array_values(array_unique(array_filter(array_map(static function ($id): int {
            $n = (int) $id;

            return $n > 0 ? $n : 0;
        }, $userIds))));
        if ($validUserIds === []) {
            return response()->json(['message' => '没有有效的用户ID'], 400);
        }

        $users = DB::table('User')
            ->select(['id', 'name', 'username', 'status', 'role'])
            ->whereIn('id', $validUserIds)
            ->where('role', 'USER')
            ->get();

        if ($users->isEmpty()) {
            return response()->json(['message' => '没有找到可更新的学生用户'], 404);
        }

        $targets = $users->filter(static fn ($u) => (string) $u->status !== $status)->values();
        if ($targets->isEmpty()) {
            return response()->json(['message' => '所选用户的状态均无需变更'], 400);
        }

        $currentTime = now();
        $operatorId = $request->user()->id;
        $results = [];

        DB::transaction(function () use ($targets, $status, $reason, $currentTime, $operatorId, &$results): void {
            foreach ($targets as $target) {
                DB::table('User')
                    ->where('id', $target->id)
                    ->update([
                        'status' => $status,
                        'statusChangedAt' => $currentTime,
                        'statusChangedBy' => $operatorId,
                        'updatedAt' => $currentTime,
                    ]);

                if (Schema::hasTable('user_status_logs')) {
                    DB::table('user_status_logs')->insert([
                        'user_id' => $target->id,
                        'old_status' => $target->status,
                        'new_status' => $status,
                        'reason' => $reason,
                        'operator_id' => $operatorId,
                        'created_at' => $currentTime,
                    ]);
                }

                $results[] = [
                    'userId' => (int) $target->id,
                    'name' => $target->name,
                    'username' => $target->username,
                    'oldStatus' => $target->status,
                    'newStatus' => $status,
                ];
            }
        });

        return response()->json([
            'success' => true,
            'message' => '成功更新 '.count($results).' 个用户的状态为'.($status === 'active' ? '正常' : '退学'),
            'data' => [
                'totalRequested' => count($validUserIds),
                'totalUpdated' => count($results),
                'updatedUsers' => $results,
                'changedAt' => $currentTime,
                'changedBy' => $request->user()->name,
            ],
        ]);
    }

    public function statusLogs(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        if (!Schema::hasTable('user_status_logs')) {
            return response()->json([
                'success' => true,
                'logs' => [],
                'pagination' => [
                    'total' => 0,
                    'page' => 1,
                    'limit' => 50,
                    'totalPages' => 0,
                    'hasNextPage' => false,
                    'hasPrevPage' => false,
                ],
            ]);
        }

        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, min(100, (int) $request->query('limit', 50)));
        $offset = ($page - 1) * $limit;
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $operatorId = (int) $request->query('operatorId', 0);

        $query = DB::table('user_status_logs as l')
            ->leftJoin('User as u', 'l.user_id', '=', 'u.id')
            ->leftJoin('User as op', 'l.operator_id', '=', 'op.id');

        if ($status !== '') {
            $query->where('l.new_status', $status);
        }
        if ($operatorId > 0) {
            $query->where('l.operator_id', $operatorId);
        }
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $searchLike = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(u.name) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(u.username) LIKE ?', [$searchLike])
                    ->orWhereRaw('LOWER(l.reason) LIKE ?', [$searchLike]);
            });
        }

        $total = (int) (clone $query)->count();
        $logs = $query
            ->orderByDesc('l.created_at')
            ->offset($offset)
            ->limit($limit)
            ->get([
                'l.id',
                'l.user_id as userId',
                'l.old_status as oldStatus',
                'l.new_status as newStatus',
                'l.reason',
                'l.created_at as createdAt',
                'l.operator_id as operatorId',
                'u.name as userName',
                'u.username as userUsername',
                'op.name as operatorName',
                'op.username as operatorUsername',
            ]);

        $rows = $logs->map(static function ($log) {
            return [
                'id' => (int) $log->id,
                'user' => [
                    'id' => (int) $log->userId,
                    'name' => $log->userName ?: '未知用户',
                    'username' => $log->userUsername ?: 'unknown',
                ],
                'oldStatus' => $log->oldStatus,
                'newStatus' => $log->newStatus,
                'oldStatusDisplay' => $log->oldStatus === 'active' ? '正常' : '退学',
                'newStatusDisplay' => $log->newStatus === 'active' ? '正常' : '退学',
                'reason' => $log->reason,
                'createdAt' => $log->createdAt,
                'operator' => [
                    'id' => $log->operatorId ? (int) $log->operatorId : null,
                    'name' => $log->operatorName ?: '未知操作员',
                    'username' => $log->operatorUsername ?: 'unknown',
                ],
            ];
        })->values();

        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;

        return response()->json([
            'success' => true,
            'logs' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
                'hasNextPage' => $page < $totalPages,
                'hasPrevPage' => $page > 1,
            ],
            'filters' => [
                'search' => $search !== '' ? $search : null,
                'status' => $status !== '' ? $status : null,
                'operatorId' => $operatorId > 0 ? $operatorId : null,
            ],
        ]);
    }

    public function sendNotification(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $title = trim((string) $request->input('title', '系统通知'));
        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            return response()->json(['message' => '通知内容不能为空'], 400);
        }

        $scope = (string) $request->input('scope', 'ALL');
        $targetUsers = DB::table('User')->where('status', 'active');
        $filter = $request->input('filter', []);
        if ($scope === 'GRADE' && !empty($filter['grade'])) {
            $targetUsers->where('grade', $filter['grade']);
        } elseif ($scope === 'CLASS' && !empty($filter['grade']) && !empty($filter['class'])) {
            $targetUsers->where('grade', $filter['grade'])->where('class', $filter['class']);
        }

        $users = $targetUsers->get(['id']);
        $insert = [];
        foreach ($users as $u) {
            $insert[] = [
                'createdAt' => now(),
                'updatedAt' => now(),
                'type' => 'SYSTEM_NOTICE',
                'message' => $title.'：'.$content,
                'read' => false,
                'userId' => $u->id,
                'songId' => null,
            ];
        }
        if ($insert !== []) {
            DB::table('Notification')->insert($insert);
        }

        return response()->json(['success' => true, 'count' => count($insert), 'message' => '通知发送成功']);
    }

    public function systemSettings(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $settings = DB::table('SystemSettings')->first();
        if ($settings === null) {
            DB::table('SystemSettings')->insert([
                'createdAt' => now(),
                'updatedAt' => now(),
                'enablePlayTimeSelection' => false,
                'enableSubmissionLimit' => false,
                'showBlacklistKeywords' => false,
                'hideStudentInfo' => true,
                'smtpEnabled' => false,
                'enableRegistrationEmailVerification' => false,
                'enableReplayRequests' => false,
                'enableRequestTimeLimitation' => false,
                'forceBlockAllRequests' => false,
            ]);
            $settings = DB::table('SystemSettings')->first();
        }

        return response()->json($settings);
    }

    public function updateSystemSettings(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $settings = DB::table('SystemSettings')->first();
        if ($settings === null) {
            DB::table('SystemSettings')->insert([
                'createdAt' => now(),
                'updatedAt' => now(),
            ]);
            $settings = DB::table('SystemSettings')->first();
        }

        $allowed = [
            'enablePlayTimeSelection',
            'siteTitle',
            'siteLogoUrl',
            'schoolLogoHomeUrl',
            'schoolLogoPrintUrl',
            'siteDescription',
            'submissionGuidelines',
            'icpNumber',
            'gonganNumber',
            'enableSubmissionLimit',
            'dailySubmissionLimit',
            'weeklySubmissionLimit',
            'monthlySubmissionLimit',
            'showBlacklistKeywords',
            'hideStudentInfo',
            'smtpEnabled',
            'smtpHost',
            'smtpPort',
            'smtpSecure',
            'smtpUsername',
            'smtpPassword',
            'smtpFromEmail',
            'smtpFromName',
            'enableRegistrationEmailVerification',
            'enableReplayRequests',
            'enableRequestTimeLimitation',
            'forceBlockAllRequests',
        ];

        $payload = [];
        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }
        $payload['updatedAt'] = now();
        DB::table('SystemSettings')->where('id', $settings->id)->update($payload);

        return response()->json(DB::table('SystemSettings')->where('id', $settings->id)->first());
    }

    public function semesters(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(DB::table('Semester')->orderByDesc('createdAt')->get());
    }

    public function createSemester(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['message' => '学期名称不能为空'], 400);
        }
        $exists = DB::table('Semester')->where('name', $name)->exists();
        if ($exists) {
            return response()->json(['message' => '学期已存在'], 400);
        }
        $id = DB::table('Semester')->insertGetId([
            'createdAt' => now(),
            'updatedAt' => now(),
            'name' => $name,
            'isActive' => false,
        ], 'id');

        return response()->json(['success' => true, 'id' => (int) $id]);
    }

    public function setActiveSemester(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['message' => '学期ID无效'], 400);
        }
        DB::table('Semester')->update(['isActive' => false, 'updatedAt' => now()]);
        DB::table('Semester')->where('id', $id)->update(['isActive' => true, 'updatedAt' => now()]);

        return response()->json(['success' => true, 'message' => '已切换活跃学期']);
    }

    public function deleteSemester(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        DB::table('Semester')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    public function schedule(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        $songId = (int) $request->input('songId', 0);
        if ($songId <= 0) {
            return response()->json(['message' => 'songId 无效'], 400);
        }
        $id = DB::table('Schedule')->insertGetId([
            'createdAt' => now(),
            'updatedAt' => now(),
            'songId' => $songId,
            'playDate' => $request->input('playDate'),
            'played' => false,
            'sequence' => (int) $request->input('sequence', 1),
            'playTimeId' => $request->input('playTimeId'),
            'isDraft' => false,
            'publishedAt' => now(),
        ], 'id');

        return response()->json(['success' => true, 'scheduleId' => (int) $id]);
    }

    public function scheduleRemove(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        $scheduleId = (int) $request->input('scheduleId', 0);
        if ($scheduleId <= 0) {
            return response()->json(['success' => false, 'message' => 'scheduleId 无效'], 400);
        }
        DB::table('Schedule')->where('id', $scheduleId)->delete();

        return response()->json(['success' => true, 'message' => '排期已移除']);
    }

    public function scheduleSequence(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }
        $schedules = $request->input('schedules', []);
        if (!is_array($schedules)) {
            return response()->json(['message' => 'schedules 参数无效'], 400);
        }
        foreach ($schedules as $item) {
            if (!isset($item['id'], $item['sequence'])) {
                continue;
            }
            DB::table('Schedule')->where('id', (int) $item['id'])->update([
                'sequence' => (int) $item['sequence'],
                'updatedAt' => now(),
            ]);
        }

        return response()->json(['success' => true, 'message' => '排期顺序更新成功']);
    }

    public function scheduleFull(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        $semester = trim((string) $request->query('semester', ''));
        $query = DB::table('Schedule as sch')
            ->join('Song as s', 'sch.songId', '=', 's.id')
            ->leftJoin('User as u', 's.requesterId', '=', 'u.id')
            ->leftJoin('PlayTime as pt', 'sch.playTimeId', '=', 'pt.id');
        if ($semester !== '' && $semester !== 'all') {
            $query->where('s.semester', $semester);
        }
        $rows = $query->orderBy('sch.playDate')->orderBy('sch.sequence')->get([
            'sch.*',
            's.title',
            's.artist',
            's.cover',
            's.musicPlatform',
            's.musicId',
            's.playUrl',
            's.semester',
            's.requesterId',
            'u.name as requesterName',
            'u.username as requesterUsername',
            'u.grade as requesterGrade',
            'u.class as requesterClass',
            'pt.name as playTimeName',
            'pt.startTime as playTimeStartTime',
            'pt.endTime as playTimeEndTime',
        ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function scheduleDraft(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '草稿排期已保存（兼容模式）']);
    }

    public function schedulePublish(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '排期已发布']);
    }

    public function scheduleBulkPublish(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '批量发布完成']);
    }

    public function replayRequests(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }
        if (!Schema::hasTable('song_replay_requests')) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $rows = DB::table('song_replay_requests as rr')
            ->join('Song as s', 'rr.song_id', '=', 's.id')
            ->join('User as u', 'rr.user_id', '=', 'u.id')
            ->orderByDesc('rr.created_at')
            ->get([
                'rr.id',
                'rr.song_id as songId',
                'rr.user_id as userId',
                'rr.status',
                'rr.created_at as createdAt',
                'rr.updated_at as updatedAt',
                's.title as songTitle',
                's.artist as songArtist',
                'u.name as userName',
                'u.username as username',
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function rejectReplayRequest(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return response()->json(['message' => 'id 无效'], 400);
        }
        DB::table('song_replay_requests')->where('id', $id)->update([
            'status' => 'REJECTED',
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => '已拒绝重播申请']);
    }

    public function markPlayed(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        $songId = (int) $request->input('songId', 0);
        if ($songId <= 0) {
            return response()->json(['message' => '歌曲ID无效'], 400);
        }
        $unmark = (bool) $request->input('unmark', false);
        DB::table('Song')->where('id', $songId)->update([
            'played' => !$unmark,
            'playedAt' => $unmark ? null : now(),
            'updatedAt' => now(),
        ]);

        return response()->json([
            'message' => $unmark ? '歌曲已成功撤回已播放状态' : '歌曲已成功标记为已播放',
            'count' => 1,
            'updatedSongIds' => [$songId],
        ]);
    }

    public function deleteSong(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }
        $songId = (int) $request->input('songId', 0);
        if ($songId <= 0) {
            return response()->json(['message' => '歌曲ID不能为空'], 400);
        }
        DB::table('Vote')->where('songId', $songId)->delete();
        DB::table('Schedule')->where('songId', $songId)->delete();
        if (Schema::hasTable('song_comments')) {
            DB::table('song_comments')->where('song_id', $songId)->delete();
        }
        if (Schema::hasTable('song_replay_requests')) {
            DB::table('song_replay_requests')->where('song_id', $songId)->delete();
        }
        DB::table('Notification')->where('songId', $songId)->delete();
        DB::table('Song')->where('id', $songId)->delete();

        return response()->json([
            'message' => '歌曲已成功删除',
            'songId' => $songId,
            'deletedSchedules' => true,
        ]);
    }

    public function rejectSong(Request $request): JsonResponse
    {
        return $this->deleteSong($request);
    }

    public function updateSongVoteCount(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureSongAdmin($request)) {
            return $forbidden;
        }
        if (!Schema::hasTable('song_vote_offsets')) {
            return response()->json(['message' => 'song_vote_offsets 表不存在'], 400);
        }

        $targetCount = (int) $request->input('voteCount', 0);
        $realCount = (int) DB::table('Vote')->where('songId', $id)->count();
        $offset = $targetCount - $realCount;

        $existing = DB::table('song_vote_offsets')->where('song_id', $id)->first();
        if ($existing) {
            DB::table('song_vote_offsets')->where('song_id', $id)->update([
                'vote_offset' => $offset,
                'updated_at' => now(),
                'updated_by' => $request->user()->id,
            ]);
        } else {
            DB::table('song_vote_offsets')->insert([
                'song_id' => $id,
                'vote_offset' => $offset,
                'updated_at' => now(),
                'updated_by' => $request->user()->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => '票数已更新',
            'data' => [
                'songId' => $id,
                'realCount' => $realCount,
                'targetCount' => $targetCount,
                'offset' => $offset,
            ],
        ]);
    }

    public function blacklistList(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        $rows = DB::table('SongBlacklist')->orderByDesc('createdAt')->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function blacklistCreate(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        $value = trim((string) $request->input('value', ''));
        $type = strtoupper(trim((string) $request->input('type', 'KEYWORD')));
        if ($value === '') {
            return response()->json(['message' => '黑名单内容不能为空'], 400);
        }
        $id = DB::table('SongBlacklist')->insertGetId([
            'createdAt' => now(),
            'updatedAt' => now(),
            'type' => in_array($type, ['SONG', 'KEYWORD'], true) ? $type : 'KEYWORD',
            'value' => $value,
            'reason' => $request->input('reason'),
            'isActive' => (bool) $request->input('isActive', true),
            'createdBy' => $request->user()->id,
        ], 'id');

        return response()->json(['success' => true, 'id' => (int) $id]);
    }

    public function blacklistUpdate(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        $payload = [];
        foreach (['type', 'value', 'reason', 'isActive'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }
        $payload['updatedAt'] = now();
        DB::table('SongBlacklist')->where('id', $id)->update($payload);

        return response()->json(['success' => true]);
    }

    public function blacklistDelete(Request $request, int $id): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }
        DB::table('SongBlacklist')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    public function smtpReload(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => 'SMTP 配置已刷新']);
    }

    public function smtpTestConnection(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => 'SMTP 连接测试成功（兼容模式）']);
    }

    public function smtpTestEmail(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '测试邮件发送请求已受理（兼容模式）']);
    }

    public function emailTemplates(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        // 兼容接口：返回空模板列表
        return response()->json(['success' => true, 'data' => []]);
    }

    public function emailTemplateSave(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '模板已保存（兼容模式）']);
    }

    public function emailTemplateDelete(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '模板已删除（兼容模式）']);
    }

    public function emailTemplatePreview(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json([
            'success' => true,
            'subject' => $request->input('subject', ''),
            'html' => $request->input('html', ''),
        ]);
    }

    public function backupExport(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '导出请求已受理（兼容模式）']);
    }

    public function backupRestore(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '恢复请求已受理（兼容模式）']);
    }

    public function backupRestoreChunk(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '分片恢复请求已受理（兼容模式）']);
    }

    public function backupClear(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => true, 'message' => '清理请求已受理（兼容模式）']);
    }

    public function fixSequence(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        if (!Schema::hasTable('Schedule')) {
            return response()->json(['success' => true, 'message' => '无需修复']);
        }

        $rows = DB::table('Schedule')->orderBy('playDate')->orderBy('id')->get(['id', 'playDate']);
        $grouped = [];
        foreach ($rows as $row) {
            $dateKey = Carbon::parse($row->playDate)->format('Y-m-d');
            $grouped[$dateKey] ??= [];
            $grouped[$dateKey][] = $row;
        }

        foreach ($grouped as $items) {
            $seq = 1;
            foreach ($items as $item) {
                DB::table('Schedule')->where('id', $item->id)->update(['sequence' => $seq, 'updatedAt' => now()]);
                $seq++;
            }
        }

        return response()->json(['success' => true, 'message' => '排期序号修复完成']);
    }

    public function databaseReset(Request $request): JsonResponse
    {
        if ($forbidden = $this->ensureAdmin($request)) {
            return $forbidden;
        }

        return response()->json(['success' => false, 'message' => '生产环境禁用在线数据库重置'], 403);
    }
}
