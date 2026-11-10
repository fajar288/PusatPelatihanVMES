<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostController extends Controller
{
    /** GET /api/v1/posts?type=tutorial|berita|kegiatan|dokumentasi&q=&urut=new|az&per_page=24 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Post::query()->published()->ofType($request->string('type')->toString())->search($request->string('q'));
        if ($request->string('urut')->toString() === 'az') {
            $q->reorder('title');
        }
        $perPage = min(100, max(1, (int) $request->integer('per_page', 24)));

        return PostResource::collection($q->paginate($perPage)->withQueryString());
    }

    /** GET /api/v1/posts/{slug} — detail (body HTML) + terkait */
    public function show(string $slug): PostResource
    {
        $post = Post::query()->published()->where('slug', $slug)->firstOrFail();
        $others = Post::query()->published()->whereKeyNot($post->id)->get();
        $post->setRelation('related', $others->where('type', $post->type)->concat($others->where('type', '!=', $post->type))->take(3)->values());

        return new PostResource($post);
    }
}
