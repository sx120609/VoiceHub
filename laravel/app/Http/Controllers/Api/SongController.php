<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoleHelper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SongController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $semester = trim((string) $request->query('semester', ''));
        $grade = trim((string) $request->query('grade', ''));
        $scope = trim((string) $request->query('scope', ''));
        $sortBy = trim((string) $request->query('sortBy', 'createdAt'));
        $sortOrder = strtolower(trim((string) $request->query('sortOrder', 'desc'))) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('Song as s')
            ->leftJoin('User as u', 's.requesterId', '=', 'u.id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->whereRaw('"s"."title" ILIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw('"s"."artist" ILIKE ?', ['%'.$search.'%']);
            });
        }
        if ($semester !== '') {
            $query->where('s.semester', $semester);
        }
        if ($grade !== '') {
            $query->where('u.grade', $grade);
        }
        if ($scope === 'mine' && $user !== null) {
            $query->where('s.requesterId', $user->id);
        }

        $total = (clone $query)->count();

        if ($sortBy === 'title') {
            $query->orderBy('s.title', $sortOrder);
        } elseif ($sortBy === 'artist') {
            $query->orderBy('s.artist', $sortOrder);
        } elseif ($sortBy === 'playedAt') {
            $query->orderBy('s.playedAt', $sortOrder);
        } else {
            $query->orderBy('s.createdAt', $sortOrder);
        }

        $songs = $query->get([
            's.id',
            's.title',
            's.artist',
            's.played',
            's.playedAt',
            's.semester',
            's.createdAt',
            's.updatedAt',
            's.cover',
            's.musicPlatform',
            's.musicId',
            's.playUrl',
            's.preferredPlayTimeId',
            'u.id as requesterId',
            'u.name as requesterName',
            'u.username as requesterUsername',
            'u.grade as requesterGrade',
            'u.class as requesterClass',
        ]);

        $songIds = $songs->pluck('id')->map(static fn ($v) => (int) $v)->values()->all();
        $voteCounts = $this->buildVoteCountMap($songIds);
        $scheduledMap = $this->buildScheduleMap($songIds);
        $commentCounts = $this->buildCommentCountMap($songIds);
        $replayData = $this->buildReplayDataMap($songIds);
        $userVotes = $this->buildUserVoteSet($user?->id, $songIds);
        $userReplay = $this->buildUserReplayMap($user?->id, $songIds);
        $playTimes = DB::table('PlayTime')->get()->keyBy('id');

        $hideStudentInfo = (bool) (DB::table('SystemSettings')->value('hideStudentInfo') ?? true);
        $isAdmin = RoleHelper::isSongAdminLike($user?->role);

        $result = $songs->map(function ($row) use (
            $voteCounts,
            $scheduledMap,
            $commentCounts,
            $replayData,
            $userVotes,
            $userReplay,
            $playTimes,
            $hideStudentInfo,
            $isAdmin
        ) {
            $songId = (int) $row->id;
            $requester = $row->requesterName ?: ($row->requesterUsername ?: '未知用户');
            $requesterGrade = $row->requesterGrade;
            $requesterClass = $row->requesterClass;

            if ($hideStudentInfo && !$isAdmin) {
                $requesterGrade = null;
                $requesterClass = null;
            }

            $replay = $replayData[$songId] ?? ['count' => 0, 'requesters' => []];
            $userReplayState = $userReplay[$songId] ?? null;
            $scheduleInfo = $scheduledMap[$songId] ?? null;
            $preferredPlayTime = $row->preferredPlayTimeId ? ($playTimes[$row->preferredPlayTimeId] ?? null) : null;

            return [
                'id' => $songId,
                'title' => $row->title,
                'artist' => $row->artist,
                'requester' => $requester,
                'requesterId' => $row->requesterId ? (int) $row->requesterId : null,
                'collaborators' => [],
                'voteCount' => $voteCounts[$songId] ?? 0,
                'played' => (bool) $row->played,
                'playedAt' => $row->playedAt,
                'semester' => $row->semester,
                'createdAt' => $row->createdAt,
                'updatedAt' => $row->updatedAt,
                'requestedAt' => $row->createdAt ? Carbon::parse($row->createdAt)->toDateTimeString() : null,
                'scheduled' => $scheduleInfo !== null,
                'cover' => $row->cover,
                'musicPlatform' => $row->musicPlatform,
                'musicId' => $row->musicId,
                'playUrl' => $row->playUrl,
                'requesterGrade' => $requesterGrade,
                'requesterClass' => $requesterClass,
                'replayRequested' => $userReplayState ? ($userReplayState['status'] === 'PENDING') : false,
                'replayRequestCount' => $replay['count'],
                'commentCount' => $commentCounts[$songId] ?? 0,
                'isReplay' => ($replay['count'] ?? 0) > 0,
                'replayRequesters' => $replay['requesters'],
                'voted' => in_array($songId, $userVotes, true),
                'preferredPlayTimeId' => $row->preferredPlayTimeId ? (int) $row->preferredPlayTimeId : null,
                'preferredPlayTime' => $preferredPlayTime,
                'scheduleDate' => $scheduleInfo['playDate'] ?? null,
                'schedulePlayed' => $scheduleInfo['played'] ?? false,
                'replayRequestStatus' => $userReplayState['status'] ?? null,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'songs' => $result,
                'total' => (int) $total,
            ],
        ]);
    }

    public function publicSchedules(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $semester = trim((string) $request->query('semester', ''));

        $query = DB::table('Schedule as sch')
            ->join('Song as s', 'sch.songId', '=', 's.id')
            ->leftJoin('User as u', 's.requesterId', '=', 'u.id')
            ->leftJoin('PlayTime as pt', 'sch.playTimeId', '=', 'pt.id')
            ->where('sch.isDraft', false);

        if ($semester !== '') {
            $query->where('s.semester', $semester);
        }

        $rows = $query
            ->orderBy('sch.playDate')
            ->orderBy('sch.sequence')
            ->get([
                'sch.id',
                'sch.playDate',
                'sch.sequence',
                'sch.played',
                'sch.playTimeId',
                's.id as songId',
                's.title',
                's.artist',
                's.cover',
                's.musicPlatform',
                's.musicId',
                's.playUrl',
                's.semester',
                's.createdAt as songCreatedAt',
                's.played as songPlayed',
                'u.id as requesterId',
                'u.name as requesterName',
                'u.username as requesterUsername',
                'u.grade as requesterGrade',
                'u.class as requesterClass',
                'pt.id as ptId',
                'pt.name as ptName',
                'pt.startTime as ptStartTime',
                'pt.endTime as ptEndTime',
                'pt.enabled as ptEnabled',
            ]);

        $songIds = $rows->pluck('songId')->map(static fn ($v) => (int) $v)->unique()->values()->all();
        $voteCounts = $this->buildVoteCountMap($songIds);
        $replayData = $this->buildReplayDataMap($songIds);

        $hideStudentInfo = (bool) (DB::table('SystemSettings')->value('hideStudentInfo') ?? true);
        $isAdmin = RoleHelper::isSongAdminLike($user?->role);

        $data = $rows->map(function ($row) use ($voteCounts, $replayData, $hideStudentInfo, $isAdmin) {
            $songId = (int) $row->songId;
            $requesterGrade = $row->requesterGrade;
            $requesterClass = $row->requesterClass;
            if ($hideStudentInfo && !$isAdmin) {
                $requesterGrade = null;
                $requesterClass = null;
            }

            $replay = $replayData[$songId] ?? ['count' => 0, 'requesters' => []];

            return [
                'id' => (int) $row->id,
                'playDate' => Carbon::parse($row->playDate)->format('Y-m-d'),
                'sequence' => (int) ($row->sequence ?? 1),
                'played' => (bool) $row->played,
                'playTimeId' => $row->playTimeId ? (int) $row->playTimeId : null,
                'playTime' => $row->ptId ? [
                    'id' => (int) $row->ptId,
                    'name' => $row->ptName,
                    'startTime' => $row->ptStartTime,
                    'endTime' => $row->ptEndTime,
                    'enabled' => (bool) $row->ptEnabled,
                ] : null,
                'song' => [
                    'id' => $songId,
                    'title' => $row->title,
                    'artist' => $row->artist,
                    'requester' => $row->requesterName ?: ($row->requesterUsername ?: '未知用户'),
                    'requesterGrade' => $requesterGrade,
                    'requesterClass' => $requesterClass,
                    'collaborators' => [],
                    'voteCount' => $voteCounts[$songId] ?? 0,
                    'played' => (bool) $row->songPlayed,
                    'cover' => $row->cover,
                    'musicPlatform' => $row->musicPlatform,
                    'musicId' => $row->musicId,
                    'playUrl' => $row->playUrl,
                    'semester' => $row->semester,
                    'requestedAt' => $row->songCreatedAt ? Carbon::parse($row->songCreatedAt)->toDateTimeString() : null,
                    'replayRequestCount' => $replay['count'],
                    'replayRequesters' => $replay['requesters'],
                    'isReplay' => ($replay['count'] ?? 0) > 0,
                ],
            ];
        })->values();

        return response()->json($data);
    }

    public function openSongs(Request $request): JsonResponse
    {
        $songs = $this->index($request)->getData(true);

        return response()->json([
            'success' => true,
            'data' => [
                'songs' => $songs['data']['songs'] ?? [],
                'pagination' => [
                    'page' => 1,
                    'limit' => count($songs['data']['songs'] ?? []),
                    'total' => $songs['data']['total'] ?? 0,
                    'totalPages' => 1,
                ],
                'filters' => [
                    'search' => $request->query('search'),
                    'semester' => $request->query('semester'),
                ],
            ],
        ]);
    }

    public function openSchedules(Request $request): JsonResponse
    {
        $schedules = $this->publicSchedules($request)->getData(true);

        return response()->json([
            'success' => true,
            'data' => [
                'schedules' => $schedules,
                'total' => count($schedules),
                'semester' => $request->query('semester'),
            ],
        ]);
    }

    public function requestSong(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $title = trim((string) $request->input('title', ''));
        $artist = trim((string) $request->input('artist', ''));
        if ($title === '' || $artist === '') {
            return response()->json(['message' => '歌曲标题和歌手不能为空'], 400);
        }

        $status = $this->computeSubmissionStatus($user);
        if (($status['submissionClosed'] ?? false) && !RoleHelper::isAdminLike($user->role)) {
            return response()->json(['message' => '当前暂不开放投稿'], 403);
        }

        $currentSemester = DB::table('Semester')->where('isActive', true)->value('name');
        $duplicate = DB::table('Song')
            ->whereRaw('LOWER("title") = LOWER(?)', [$title])
            ->whereRaw('LOWER("artist") = LOWER(?)', [$artist])
            ->where('played', false)
            ->when($currentSemester !== null, fn ($q) => $q->where('semester', $currentSemester))
            ->exists();
        if ($duplicate) {
            return response()->json(['message' => '该歌曲已经在列表中，不能重复投稿'], 400);
        }

        $hitRequestId = null;
        if (($status['timeLimitationEnabled'] ?? false) && !empty($status['currentTimePeriod']['id'])) {
            $hitRequestId = (int) $status['currentTimePeriod']['id'];
        }

        $songId = DB::table('Song')->insertGetId([
            'createdAt' => now(),
            'updatedAt' => now(),
            'title' => $title,
            'artist' => $artist,
            'requesterId' => $user->id,
            'played' => false,
            'playedAt' => null,
            'semester' => $currentSemester,
            'preferredPlayTimeId' => $request->input('preferredPlayTimeId'),
            'cover' => $request->input('cover'),
            'playUrl' => $request->input('playUrl'),
            'musicPlatform' => $request->input('musicPlatform'),
            'musicId' => $request->input('musicId'),
            'hitRequestId' => $hitRequestId,
        ], 'id');

        if ($hitRequestId !== null && Schema::hasTable('RequestTime')) {
            DB::table('RequestTime')
                ->where('id', $hitRequestId)
                ->update([
                    'accepted' => DB::raw('COALESCE("accepted", 0) + 1'),
                    'updatedAt' => now(),
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => '点歌成功',
            'songId' => (int) $songId,
        ]);
    }

    public function vote(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $songId = (int) ($request->input('songId') ?? $request->input('id') ?? 0);
        if ($songId <= 0) {
            return response()->json(['message' => '歌曲ID不能为空'], 400);
        }

        $action = (string) $request->input('action', 'vote');
        $isUnvote = in_array($action, ['unvote', 'cancel'], true) || (bool) $request->input('unvote', false);

        $song = DB::table('Song')->where('id', $songId)->first();
        if ($song === null) {
            return response()->json(['message' => '歌曲不存在'], 404);
        }

        $existing = DB::table('Vote')
            ->where('songId', $songId)
            ->where('userId', $user->id)
            ->first();

        if ($isUnvote) {
            $changed = $existing !== null;
            if ($changed) {
                DB::table('Vote')->where('id', $existing->id)->delete();
            }
            $voteCount = $this->buildVoteCountMap([$songId])[$songId] ?? 0;

            return response()->json([
                'success' => true,
                'message' => $changed ? '取消投票成功' : '当前已是未投票状态',
                'data' => [
                    'songId' => $songId,
                    'voted' => false,
                    'voteCount' => $voteCount,
                    'changed' => $changed,
                ],
            ]);
        }

        if ((bool) $song->played) {
            return response()->json(['message' => '该歌曲已播放，无法进行投票操作'], 400);
        }
        $scheduled = DB::table('Schedule')
            ->where('songId', $songId)
            ->where('isDraft', false)
            ->exists();
        if ($scheduled) {
            return response()->json(['message' => '该歌曲已排期，无法进行投票操作'], 400);
        }
        if ((int) $song->requesterId === (int) $user->id) {
            return response()->json(['message' => '不允许自己给自己投票'], 400);
        }

        $currentSemester = DB::table('Semester')->where('isActive', true)->value('name');
        if ($currentSemester && (string) $song->semester !== (string) $currentSemester) {
            return response()->json(['message' => '非活跃学期，无法进行投票操作'], 400);
        }

        if ($existing !== null) {
            $voteCount = $this->buildVoteCountMap([$songId])[$songId] ?? 0;

            return response()->json([
                'success' => true,
                'message' => '你已经为这首歌投过票了',
                'data' => [
                    'songId' => $songId,
                    'voted' => true,
                    'voteCount' => $voteCount,
                    'changed' => false,
                ],
            ]);
        }

        DB::table('Vote')->insert([
            'songId' => $songId,
            'userId' => $user->id,
            'createdAt' => now(),
        ]);

        $voteCount = $this->buildVoteCountMap([$songId])[$songId] ?? 0;

        return response()->json([
            'success' => true,
            'message' => '投票成功',
            'data' => [
                'songId' => $songId,
                'voted' => true,
                'voteCount' => $voteCount,
                'changed' => true,
            ],
        ]);
    }

    public function withdraw(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $songId = (int) $request->input('songId', 0);
        if ($songId <= 0) {
            return response()->json(['message' => '歌曲ID不能为空'], 400);
        }

        $song = DB::table('Song')->where('id', $songId)->first();
        if ($song === null) {
            return response()->json(['message' => '歌曲不存在'], 404);
        }

        if ((int) $song->requesterId !== (int) $user->id && !RoleHelper::isAdminLike($user->role)) {
            return response()->json(['message' => '只能撤回自己的投稿'], 403);
        }
        if ((bool) $song->played) {
            return response()->json(['message' => '已播放的歌曲不能撤回'], 400);
        }
        if (DB::table('Schedule')->where('songId', $songId)->where('isDraft', false)->exists()) {
            return response()->json(['message' => '已排期的歌曲不能撤回'], 400);
        }

        if (Schema::hasTable('song_collaborators')) {
            DB::table('song_collaborators')->where('song_id', $songId)->delete();
        }
        DB::table('Vote')->where('songId', $songId)->delete();
        if (Schema::hasTable('song_comments')) {
            DB::table('song_comments')->where('song_id', $songId)->delete();
        }
        if (Schema::hasTable('song_replay_requests')) {
            DB::table('song_replay_requests')->where('song_id', $songId)->delete();
        }
        DB::table('Notification')->where('songId', $songId)->delete();

        if (!empty($song->hitRequestId) && Schema::hasTable('RequestTime')) {
            DB::table('RequestTime')
                ->where('id', $song->hitRequestId)
                ->update([
                    'accepted' => DB::raw('GREATEST(0, COALESCE("accepted", 0) - 1)'),
                    'updatedAt' => now(),
                ]);
        }

        DB::table('Song')->where('id', $songId)->delete();

        return response()->json([
            'message' => '歌曲已成功撤回',
            'songId' => $songId,
            'quotaReturned' => true,
        ]);
    }

    public function replay(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $songId = (int) ($request->input('songId') ?? $request->input('id') ?? 0);
        if ($songId <= 0) {
            return response()->json(['message' => '歌曲ID不能为空'], 400);
        }

        $action = (string) $request->input('action', 'request');
        $isCancel = in_array($action, ['cancel', 'withdraw', 'unrequest'], true) || (bool) $request->input('cancel', false);

        if ($isCancel) {
            $deleted = DB::table('song_replay_requests')
                ->where('song_id', $songId)
                ->where('user_id', $user->id)
                ->where('status', 'PENDING')
                ->delete();

            return response()->json([
                'success' => true,
                'message' => $deleted > 0 ? '已取消重播申请' : '重播申请已是取消状态',
                'data' => [
                    'songId' => $songId,
                    'replayRequested' => false,
                    'replayRequestStatus' => null,
                    'replayRequestCount' => $this->replayRequestCount($songId),
                    'changed' => $deleted > 0,
                ],
            ]);
        }

        $enabled = (bool) (DB::table('SystemSettings')->value('enableReplayRequests') ?? false);
        if (!$enabled) {
            return response()->json(['message' => '重播申请功能未开启'], 403);
        }

        $song = DB::table('Song')->where('id', $songId)->first();
        if ($song === null) {
            return response()->json(['message' => '歌曲不存在'], 404);
        }
        if (!(bool) $song->played) {
            return response()->json(['message' => '该歌曲尚未播放，无法申请重播'], 400);
        }

        $currentSemester = DB::table('Semester')->where('isActive', true)->value('name');
        if ($currentSemester && (string) $song->semester !== (string) $currentSemester) {
            return response()->json(['message' => '只能申请重播当前学期的歌曲'], 400);
        }

        $existing = DB::table('song_replay_requests')
            ->where('song_id', $songId)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && (string) $existing->status === 'PENDING') {
            return response()->json([
                'success' => true,
                'message' => '您已经申请过重播该歌曲',
                'data' => [
                    'songId' => $songId,
                    'replayRequested' => true,
                    'replayRequestStatus' => 'PENDING',
                    'replayRequestCount' => $this->replayRequestCount($songId),
                    'changed' => false,
                ],
            ]);
        }

        if ($existing) {
            DB::table('song_replay_requests')
                ->where('id', $existing->id)
                ->update([
                    'status' => 'PENDING',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
        } else {
            DB::table('song_replay_requests')->insert([
                'song_id' => $songId,
                'user_id' => $user->id,
                'status' => 'PENDING',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $existing ? '重新申请重播成功' : '申请重播成功',
            'data' => [
                'songId' => $songId,
                'replayRequested' => true,
                'replayRequestStatus' => 'PENDING',
                'replayRequestCount' => $this->replayRequestCount($songId),
                'changed' => true,
            ],
        ]);
    }

    public function submissionStatus(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->computeSubmissionStatus($user));
    }

    public function voters(int $songId): JsonResponse
    {
        if ($songId <= 0) {
            return response()->json(['message' => '无效的歌曲ID'], 400);
        }

        $rows = DB::table('Vote as v')
            ->join('User as u', 'v.userId', '=', 'u.id')
            ->where('v.songId', $songId)
            ->orderBy('v.createdAt')
            ->get([
                'u.id',
                'u.name',
                'u.username',
                'u.grade',
                'u.class',
                'v.createdAt',
            ]);

        $data = $rows->map(function ($row, $index) {
            return [
                'id' => (int) $row->id,
                'name' => $row->name ?: $row->username,
                'displayName' => $row->name ?: $row->username,
                'grade' => $row->grade,
                'class' => $row->class,
                'votedAt' => $row->createdAt,
                'rank' => $index + 1,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => [
                'voters' => $data,
                'total' => $data->count(),
            ],
        ]);
    }

    public function updateSong(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (!RoleHelper::isSongAdminLike($user->role)) {
            return response()->json(['message' => '没有权限访问'], 403);
        }

        $song = DB::table('Song')->where('id', $id)->first();
        if ($song === null) {
            return response()->json(['message' => '歌曲不存在'], 404);
        }

        $payload = [];
        foreach (['title', 'artist', 'cover', 'playUrl', 'musicPlatform', 'musicId', 'semester', 'preferredPlayTimeId'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = $request->input($field);
            }
        }
        if ($request->has('played')) {
            $payload['played'] = (bool) $request->input('played');
        }
        if ($request->has('playedAt')) {
            $payload['playedAt'] = $request->input('playedAt');
        }
        $payload['updatedAt'] = now();

        DB::table('Song')->where('id', $id)->update($payload);

        return response()->json([
            'success' => true,
            'message' => '歌曲更新成功',
        ]);
    }

    public function addSong(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (!RoleHelper::isSongAdminLike($user->role)) {
            return response()->json(['message' => '没有权限访问'], 403);
        }

        $title = trim((string) $request->input('title', ''));
        $artist = trim((string) $request->input('artist', ''));
        if ($title === '' || $artist === '') {
            return response()->json(['message' => '歌曲标题和歌手不能为空'], 400);
        }

        $songId = DB::table('Song')->insertGetId([
            'createdAt' => now(),
            'updatedAt' => now(),
            'title' => $title,
            'artist' => $artist,
            'requesterId' => (int) ($request->input('requesterId') ?? $user->id),
            'played' => (bool) ($request->input('played', false)),
            'playedAt' => $request->input('playedAt'),
            'semester' => $request->input('semester'),
            'preferredPlayTimeId' => $request->input('preferredPlayTimeId'),
            'cover' => $request->input('cover'),
            'playUrl' => $request->input('playUrl'),
            'musicPlatform' => $request->input('musicPlatform'),
            'musicId' => $request->input('musicId'),
        ], 'id');

        return response()->json([
            'success' => true,
            'message' => '歌曲添加成功',
            'songId' => (int) $songId,
        ]);
    }

    public function importSongs(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (!RoleHelper::isSongAdminLike($user->role)) {
            return response()->json(['message' => '没有权限访问'], 403);
        }

        $items = $request->input('songs', []);
        if (!is_array($items)) {
            return response()->json(['message' => '导入数据格式错误'], 400);
        }

        $inserted = 0;
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $artist = trim((string) ($item['artist'] ?? ''));
            if ($title === '' || $artist === '') {
                continue;
            }
            DB::table('Song')->insert([
                'createdAt' => now(),
                'updatedAt' => now(),
                'title' => $title,
                'artist' => $artist,
                'requesterId' => (int) ($item['requesterId'] ?? $user->id),
                'played' => false,
                'playedAt' => null,
                'semester' => $item['semester'] ?? null,
                'preferredPlayTimeId' => $item['preferredPlayTimeId'] ?? null,
                'cover' => $item['cover'] ?? null,
                'playUrl' => $item['playUrl'] ?? null,
                'musicPlatform' => $item['musicPlatform'] ?? null,
                'musicId' => $item['musicId'] ?? null,
            ]);
            $inserted++;
        }

        return response()->json([
            'success' => true,
            'message' => '导入完成',
            'data' => [
                'inserted' => $inserted,
                'total' => count($items),
            ],
        ]);
    }

    public function collaboratorsReply(): JsonResponse
    {
        return response()->json([
            'message' => '联合投稿功能已下线',
        ], 410);
    }

    private function buildVoteCountMap(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        $rows = DB::table('Vote')
            ->select('songId', DB::raw('COUNT(id) as count'))
            ->whereIn('songId', $songIds)
            ->groupBy('songId')
            ->get();

        $map = [];
        foreach ($songIds as $songId) {
            $map[(int) $songId] = 0;
        }
        foreach ($rows as $row) {
            $map[(int) $row->songId] = (int) $row->count;
        }

        if (Schema::hasTable('song_vote_offsets')) {
            $offsetRows = DB::table('song_vote_offsets')
                ->select(['song_id as songId', 'vote_offset as voteOffset'])
                ->whereIn('song_id', $songIds)
                ->get();
            foreach ($offsetRows as $row) {
                $songId = (int) $row->songId;
                $map[$songId] = max(0, (int) ($map[$songId] ?? 0) + (int) ($row->voteOffset ?? 0));
            }
        }

        return $map;
    }

    private function buildScheduleMap(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        $rows = DB::table('Schedule')
            ->select(['songId', 'playDate', 'played'])
            ->whereIn('songId', $songIds)
            ->where('isDraft', false)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $songId = (int) $row->songId;
            if (!isset($map[$songId])) {
                $map[$songId] = [
                    'playDate' => $row->playDate,
                    'played' => (bool) $row->played,
                ];
            }
        }

        return $map;
    }

    private function buildCommentCountMap(array $songIds): array
    {
        if ($songIds === [] || !Schema::hasTable('song_comments')) {
            return [];
        }
        $rows = DB::table('song_comments')
            ->select('song_id as songId', DB::raw('COUNT(id) as count'))
            ->whereIn('song_id', $songIds)
            ->groupBy('song_id')
            ->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->songId] = (int) $row->count;
        }

        return $map;
    }

    private function buildReplayDataMap(array $songIds): array
    {
        if ($songIds === [] || !Schema::hasTable('song_replay_requests')) {
            return [];
        }

        $rows = DB::table('song_replay_requests as rr')
            ->leftJoin('User as u', 'rr.user_id', '=', 'u.id')
            ->whereIn('rr.song_id', $songIds)
            ->whereIn('rr.status', ['PENDING', 'FULFILLED'])
            ->orderByDesc('rr.created_at')
            ->get([
                'rr.song_id as songId',
                'rr.user_id as userId',
                'rr.status',
                'rr.created_at as createdAt',
                'u.name',
                'u.username',
                'u.grade',
                'u.class',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $songId = (int) $row->songId;
            if (!isset($map[$songId])) {
                $map[$songId] = [
                    'count' => 0,
                    'requesters' => [],
                ];
            }
            $map[$songId]['count']++;
            if (count($map[$songId]['requesters']) < 3) {
                $map[$songId]['requesters'][] = [
                    'id' => (int) $row->userId,
                    'name' => $row->name ?: ($row->username ?: '未知用户'),
                    'displayName' => $row->name ?: ($row->username ?: '未知用户'),
                    'grade' => $row->grade,
                    'class' => $row->class,
                    'status' => $row->status,
                    'createdAt' => $row->createdAt,
                ];
            }
        }

        return $map;
    }

    private function buildUserVoteSet(?int $userId, array $songIds): array
    {
        if (!$userId || $songIds === []) {
            return [];
        }

        return DB::table('Vote')
            ->where('userId', $userId)
            ->whereIn('songId', $songIds)
            ->pluck('songId')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function buildUserReplayMap(?int $userId, array $songIds): array
    {
        if (!$userId || $songIds === [] || !Schema::hasTable('song_replay_requests')) {
            return [];
        }

        $rows = DB::table('song_replay_requests')
            ->where('user_id', $userId)
            ->whereIn('song_id', $songIds)
            ->get(['song_id as songId', 'status', 'updated_at as updatedAt']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->songId] = [
                'status' => $row->status,
                'updatedAt' => $row->updatedAt,
            ];
        }

        return $map;
    }

    private function replayRequestCount(int $songId): int
    {
        if (!Schema::hasTable('song_replay_requests')) {
            return 0;
        }

        return (int) DB::table('song_replay_requests')
            ->where('song_id', $songId)
            ->whereIn('status', ['PENDING', 'FULFILLED'])
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSubmissionStatus(User $user): array
    {
        $settings = DB::table('SystemSettings')->first();
        $status = [
            'limitEnabled' => (bool) ($settings->enableSubmissionLimit ?? false),
            'dailyLimit' => null,
            'weeklyLimit' => null,
            'monthlyLimit' => null,
            'dailyUsed' => 0,
            'weeklyUsed' => 0,
            'monthlyUsed' => 0,
            'dailyRemaining' => null,
            'weeklyRemaining' => null,
            'monthlyRemaining' => null,
            'submissionClosed' => false,
            'timeLimitationEnabled' => (bool) ($settings->enableRequestTimeLimitation ?? false),
            'currentTimePeriod' => null,
        ];

        $isAdmin = RoleHelper::isAdminLike($user->role);

        if ($status['timeLimitationEnabled']) {
            $now = Carbon::now('Asia/Shanghai')->toDateTimeString();
            $currentTimePeriod = Schema::hasTable('RequestTime')
                ? DB::table('RequestTime')
                    ->where('enabled', true)
                    ->where('startTime', '<=', $now)
                    ->where('endTime', '>', $now)
                    ->first()
                : null;

            if ($currentTimePeriod) {
                $status['currentTimePeriod'] = $currentTimePeriod;
            } elseif (!$isAdmin) {
                $status['submissionClosed'] = true;
            }
        }

        if (!$status['limitEnabled']) {
            return $status;
        }

        $dailyLimit = $settings->dailySubmissionLimit;
        $weeklyLimit = $settings->weeklySubmissionLimit;
        $monthlyLimit = $settings->monthlySubmissionLimit;
        $effectiveLimit = null;
        $limitType = null;

        if ($dailyLimit !== null) {
            $effectiveLimit = (int) $dailyLimit;
            $limitType = 'daily';
        } elseif ($weeklyLimit !== null) {
            $effectiveLimit = (int) $weeklyLimit;
            $limitType = 'weekly';
        } elseif ($monthlyLimit !== null) {
            $effectiveLimit = (int) $monthlyLimit;
            $limitType = 'monthly';
        }

        if ($effectiveLimit === 0) {
            $status['dailyLimit'] = $limitType === 'daily' ? $effectiveLimit : null;
            $status['weeklyLimit'] = $limitType === 'weekly' ? $effectiveLimit : null;
            $status['monthlyLimit'] = $limitType === 'monthly' ? $effectiveLimit : null;
            $status['dailyRemaining'] = 0;
            $status['weeklyRemaining'] = 0;
            $status['monthlyRemaining'] = 0;
            $status['submissionClosed'] = !$isAdmin;

            return $status;
        }

        if ($limitType === 'daily' && $effectiveLimit > 0) {
            $start = Carbon::now('Asia/Shanghai')->startOfDay();
            $end = Carbon::now('Asia/Shanghai')->endOfDay();
            $used = (int) DB::table('Song')
                ->where('requesterId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->count();
            $status['dailyLimit'] = $effectiveLimit;
            $status['dailyUsed'] = $used;
            $status['dailyRemaining'] = max(0, $effectiveLimit - $used);
        } elseif ($limitType === 'weekly' && $effectiveLimit > 0) {
            $start = Carbon::now('Asia/Shanghai')->startOfWeek(Carbon::MONDAY);
            $end = Carbon::now('Asia/Shanghai')->endOfWeek(Carbon::SUNDAY);
            $used = (int) DB::table('Song')
                ->where('requesterId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->count();
            $status['weeklyLimit'] = $effectiveLimit;
            $status['weeklyUsed'] = $used;
            $status['weeklyRemaining'] = max(0, $effectiveLimit - $used);
        } elseif ($limitType === 'monthly' && $effectiveLimit > 0) {
            $start = Carbon::now('Asia/Shanghai')->startOfMonth();
            $end = Carbon::now('Asia/Shanghai')->endOfMonth();
            $used = (int) DB::table('Song')
                ->where('requesterId', $user->id)
                ->whereBetween('createdAt', [$start, $end])
                ->count();
            $status['monthlyLimit'] = $effectiveLimit;
            $status['monthlyUsed'] = $used;
            $status['monthlyRemaining'] = max(0, $effectiveLimit - $used);
        }

        return $status;
    }
}
