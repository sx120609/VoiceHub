<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SongCommentController extends Controller
{
    public function counts(Request $request): JsonResponse
    {
        $rawSongIds = trim((string) $request->query('songIds', ''));
        if ($rawSongIds === '') {
            return response()->json([
                'success' => true,
                'data' => ['counts' => new \stdClass()],
            ]);
        }

        $songIds = collect(explode(',', $rawSongIds))
            ->map(static fn ($value) => (int) trim($value))
            ->filter(static fn ($id) => $id > 0)
            ->unique()
            ->take(200)
            ->values();

        if ($songIds->isEmpty()) {
            return response()->json(['message' => '无效的歌曲ID列表'], 400);
        }

        $rows = DB::table('song_comments')
            ->select('song_id as songId', DB::raw('COUNT(id) as count'))
            ->whereIn('song_id', $songIds->all())
            ->groupBy('song_id')
            ->get();

        $map = [];
        foreach ($songIds as $songId) {
            $map[$songId] = 0;
        }
        foreach ($rows as $row) {
            $map[(int) $row->songId] = (int) $row->count;
        }

        return response()->json([
            'success' => true,
            'data' => ['counts' => $map],
        ]);
    }

    public function list(Request $request): JsonResponse
    {
        $songId = (int) $request->query('songId', 0);
        if ($songId <= 0) {
            return response()->json(['message' => '无效的歌曲ID'], 400);
        }

        $rows = DB::table('song_comments as c')
            ->leftJoin('User as u', 'c.user_id', '=', 'u.id')
            ->where('c.song_id', $songId)
            ->orderByDesc('c.created_at')
            ->get([
                'c.id',
                'c.content',
                'c.created_at as createdAt',
                'c.updated_at as updatedAt',
                'c.parent_comment_id as parentCommentId',
                'u.id as userId',
                'u.username',
                'u.name',
            ]);

        $allComments = [];
        foreach ($rows as $row) {
            $displayName = $row->name ?: ($row->username ?: ('用户'.($row->userId ?? '')));
            $allComments[] = [
                'id' => (int) $row->id,
                'content' => $row->content,
                'createdAt' => $row->createdAt,
                'updatedAt' => $row->updatedAt,
                'userId' => $row->userId ? (int) $row->userId : null,
                'userDisplayName' => $displayName,
                'parentCommentId' => $row->parentCommentId ? (int) $row->parentCommentId : null,
                'replyToUserDisplayName' => null,
                'replies' => [],
            ];
        }

        $map = [];
        foreach ($allComments as $comment) {
            $map[$comment['id']] = $comment;
        }

        $rootComments = [];
        foreach ($allComments as $comment) {
            $parentId = $comment['parentCommentId'];
            if ($parentId && isset($map[$parentId])) {
                $parent = &$map[$parentId];
                $comment['replyToUserDisplayName'] = $parent['userDisplayName'] ?? null;
                $parent['replies'][] = $comment;
                unset($parent);
            } else {
                $comment['parentCommentId'] = null;
                $rootComments[] = $comment;
            }
        }

        foreach ($rootComments as &$comment) {
            usort($comment['replies'], static fn ($a, $b) => strtotime((string) $a['createdAt']) <=> strtotime((string) $b['createdAt']));
        }
        unset($comment);

        return response()->json([
            'success' => true,
            'data' => [
                'comments' => $rootComments,
                'total' => count($allComments),
            ],
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $songId = (int) $request->input('songId', 0);
        $content = trim((string) $request->input('content', ''));
        $parentCommentId = (int) $request->input('parentCommentId', 0);
        if ($songId <= 0) {
            return response()->json(['message' => '无效的歌曲ID'], 400);
        }
        if ($content === '') {
            return response()->json(['message' => '评论内容不能为空'], 400);
        }
        if (mb_strlen($content) > 300) {
            return response()->json(['message' => '评论内容不能超过300字'], 400);
        }

        $song = DB::table('Song')->select(['id'])->where('id', $songId)->first();
        if ($song === null) {
            return response()->json(['message' => '歌曲不存在'], 404);
        }

        if ($parentCommentId > 0) {
            $parent = DB::table('song_comments')
                ->where('id', $parentCommentId)
                ->where('song_id', $songId)
                ->first();
            if ($parent === null) {
                return response()->json(['message' => '回复目标不存在或不属于该歌曲'], 404);
            }
        }

        $commentId = DB::table('song_comments')->insertGetId([
            'song_id' => $songId,
            'user_id' => $user->id,
            'content' => $content,
            'parent_comment_id' => $parentCommentId > 0 ? $parentCommentId : null,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'id');

        $count = (int) DB::table('song_comments')->where('song_id', $songId)->count();
        $displayName = $user->name ?: $user->username ?: ('用户'.$user->id);

        return response()->json([
            'success' => true,
            'message' => '评论发布成功',
            'data' => [
                'comment' => [
                    'id' => (int) $commentId,
                    'content' => $content,
                    'createdAt' => now()->toISOString(),
                    'updatedAt' => now()->toISOString(),
                    'userId' => (int) $user->id,
                    'parentCommentId' => $parentCommentId > 0 ? $parentCommentId : null,
                    'userDisplayName' => $displayName,
                ],
                'commentCount' => $count,
            ],
        ]);
    }

    public function delete(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $commentId = (int) $request->input('commentId', 0);
        if ($commentId <= 0) {
            return response()->json(['message' => '无效的评论ID'], 400);
        }

        $comment = DB::table('song_comments')
            ->select(['id', 'user_id as userId', 'song_id as songId', 'parent_comment_id as parentCommentId'])
            ->where('id', $commentId)
            ->first();

        if ($comment === null) {
            return response()->json(['message' => '评论不存在或已被删除'], 404);
        }

        $role = strtoupper((string) $user->role);
        $isAdmin = in_array($role, ['SUPER_ADMIN', 'ADMIN', 'SONG_ADMIN'], true);
        if (!$isAdmin && (int) $comment->userId !== (int) $user->id) {
            return response()->json(['message' => '无权限删除该评论'], 403);
        }

        if ((int) ($comment->parentCommentId ?? 0) === 0) {
            DB::table('song_comments')
                ->where('id', $commentId)
                ->orWhere('parent_comment_id', $commentId)
                ->delete();
        } else {
            DB::table('song_comments')->where('id', $commentId)->delete();
        }

        $count = (int) DB::table('song_comments')->where('song_id', $comment->songId)->count();

        return response()->json([
            'success' => true,
            'message' => '评论删除成功',
            'data' => [
                'commentCount' => $count,
            ],
        ]);
    }

    public function deleteById(Request $request, int $id): JsonResponse
    {
        $request->merge(['commentId' => $id]);

        return $this->delete($request);
    }
}
