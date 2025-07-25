<?php

namespace Tests\Feature\Http\Controllers;

use App\Events\NewPost;
use App\Jobs\SyncMedia;
use App\Mail\ReviewPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use JMac\Testing\Traits\AdditionalAssertions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * @see \App\Http\Controllers\PostController
 */
final class PostControllerTest extends TestCase
{
    use AdditionalAssertions, RefreshDatabase, WithFaker;

    #[Test]
    public function index_displays_view(): void
    {
        $posts = Post::factory()->count(3)->create();

        $response = $this->get(route('posts.index'));

        $response->assertOk();
        $response->assertViewIs('post.index');
        $response->assertViewHas('posts', $posts);
    }


    #[Test]
    public function store_uses_form_request_validation(): void
    {
        $this->assertActionUsesFormRequest(
            \App\Http\Controllers\PostController::class,
            'store',
            \App\Http\Requests\PostStoreRequest::class
        );
    }

    #[Test]
    public function store_saves_and_redirects(): void
    {
        $title = fake()->sentence(4);
        $content = fake()->paragraphs(3, true);
        $author = User::factory()->create();

        Mail::fake();
        Queue::fake();
        Event::fake();

        $response = $this->post(route('posts.store'), [
            'title' => $title,
            'content' => $content,
            'author_id' => $author->id,
        ]);

        $posts = Post::query()
            ->where('title', $title)
            ->where('content', $content)
            ->where('author_id', $author->id)
            ->get();
        $this->assertCount(1, $posts);
        $post = $posts->first();

        $response->assertRedirect(route('posts.index'));
        $response->assertSessionHas('post.title', $post->title);

        Mail::assertSent(ReviewPost::class, function ($mail) use ($post) {
            return $mail->hasTo($post->author->email) && $mail->post->is($post);
        });
        Queue::assertPushed(SyncMedia::class, function ($job) use ($post) {
            return $job->post->is($post);
        });
        Event::assertDispatched(NewPost::class, function ($event) use ($post) {
            return $event->post->is($post);
        });
    }
}
