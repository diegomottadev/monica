<?php

namespace Tests\Feature\Controllers\Vault\ManageJournals;

use App\Domains\Vault\ManageJournals\Web\Controllers\PostController;
use App\Models\Contact;
use App\Models\ContactFeedItem;
use App\Models\Journal;
use App\Models\Post;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(PostController::class)]
class PostControllerTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Vault $vault;

    private Journal $journal;

    private Post $post;

    /** @var array<string, Contact> */
    private array $contacts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->vault = $this->setPermissionInVault(
            $this->user,
            Vault::PERMISSION_EDIT,
            $this->createVault($this->user->account)
        );
        $this->journal = Journal::factory()->create(['vault_id' => $this->vault->id]);
        $this->post = Post::factory()->create(['journal_id' => $this->journal->id]);
        $this->contacts = [
            'a' => Contact::factory()->create(['vault_id' => $this->vault->id]),
            'b' => Contact::factory()->create(['vault_id' => $this->vault->id]),
        ];
    }

    #[Test]
    public function it_updates_the_post_and_returns_its_statistics(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->updateUrl(), $this->payload([], 'New title'));

        $response->assertOk();
        $response->assertJsonStructure(['data']);
        $this->assertDatabaseHas('posts', [
            'id' => $this->post->id,
            'title' => 'New title',
            'written_at' => '2026-01-01 00:00:00',
        ]);
    }

    #[Test]
    #[DataProvider('contactTransitions')]
    public function it_keeps_exactly_the_requested_contacts_in_the_post(array $before, array $requested, array $expected): void
    {
        $this->post->contacts()->attach($this->contactIds($before));

        $this->actingAs($this->user)
            ->putJson($this->updateUrl(), $this->payload($requested))
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            $this->contactIds($expected),
            $this->post->contacts()->pluck('contacts.id')->all()
        );
    }

    public static function contactTransitions(): array
    {
        return [
            'EX-01 adds contacts to a post without contacts' => [[], ['a', 'b'], ['a', 'b']],
            'EX-02 keeps the same contacts' => [['a', 'b'], ['a', 'b'], ['a', 'b']],
            'EX-03 removes one contact' => [['a', 'b'], ['a'], ['a']],
            'EX-04 replaces a contact' => [['a'], ['b'], ['b']],
            'EX-05 removes all contacts' => [['a', 'b'], [], []],
        ];
    }

    #[Test]
    public function it_logs_a_contact_only_once_when_the_post_is_saved_several_times(): void
    {
        $this->actingAs($this->user);

        foreach (range(1, 5) as $save) {
            $this->putJson($this->updateUrl(), $this->payload(['a', 'b']))->assertOk();
        }

        foreach ($this->contacts as $contact) {
            $this->assertEquals(
                1,
                ContactFeedItem::where('contact_id', $contact->id)
                    ->where('action', ContactFeedItem::ACTION_ADDED_TO_POST)
                    ->count()
            );
        }
    }

    private function updateUrl(): string
    {
        return route('post.update', [
            'vault' => $this->vault->id,
            'journal' => $this->journal->id,
            'post' => $this->post->id,
        ]);
    }

    private function payload(array $contactKeys, string $title = 'Title'): array
    {
        return [
            'title' => $title,
            'sections' => [['id' => 0, 'label' => 'Section']],
            'date' => '2026-01-01',
            'contacts' => array_map(fn (string $key) => ['id' => $this->contacts[$key]->id], $contactKeys),
        ];
    }

    private function contactIds(array $keys): array
    {
        return array_map(fn (string $key) => $this->contacts[$key]->id, $keys);
    }
}
