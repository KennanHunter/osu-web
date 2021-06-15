<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace Tests;

use App\Events\NewPrivateNotificationEvent;
use App\Jobs\Notifications\BeatmapsetDisqualify;
use App\Models\Beatmap;
use App\Models\Beatmapset;
use App\Models\BeatmapsetEvent;
use App\Models\Notification;
use App\Models\User;
use App\Models\UserNotificationOption;
use Event;
use Queue;

class BeatmapsetDisqualifyTest extends TestCase
{
    /** @var Beatmapset */
    protected $beatmapset;

    /** @var User */
    protected $sender;

    /** @var User */
    protected $user;

    #region notification tests
    public function testDuplicateNotificationNotSent()
    {
        $this->beatmapset->watches()->create(['user_id' => $this->user->getKey()]);
        $this->createNotificationOption();

        $this->disqualify()->assertStatus(200);

        Queue::assertPushed(BeatmapsetDisqualify::class);

        $this->runFakeQueue();

        $events = Event::dispatched(NewPrivateNotificationEvent::class, function (NewPrivateNotificationEvent $event) {
            if ($event->notification->name === Notification::BEATMAPSET_DISQUALIFY) {
                $this->assertSame(array_unique($event->getReceiverIds()), $event->getReceiverIds());

                return true;
            }

            return false;
        });

        $this->assertSame(1, $events->count());
    }

    public function testNotificationSentIfWatching()
    {
        $this->beatmapset->watches()->create(['user_id' => $this->user->getKey()]);

        $this->disqualify()->assertStatus(200);

        Queue::assertPushed(BeatmapsetDisqualify::class);

        $this->runFakeQueue();

        Event::assertDispatched(NewPrivateNotificationEvent::class, function (NewPrivateNotificationEvent $event) {
            return $event->notification->name === Notification::BEATMAPSET_DISQUALIFY
                && in_array($this->user->getKey(), $event->getReceiverIds(), true);
        });
    }

    /**
     * @dataProvider booleanDataProvider
     */
    public function testNotificationSentWithPushNotificationDeliveryOption($pushEnabled)
    {
        $this->beatmapset->watches()->create(['user_id' => $this->user->getKey()]);
        $this->user->notificationOptions()->create([
            'name' => UserNotificationOption::BEATMAPSET_MODDING,
        ])->update(['details' => ['push' => $pushEnabled]]);

        $this->disqualify()->assertStatus(200);

        if ($pushEnabled) {
            Queue::assertPushed(BeatmapsetDisqualify::class);

            $this->runFakeQueue();

            Event::assertDispatched(NewPrivateNotificationEvent::class, function (NewPrivateNotificationEvent $event) {
                return $event->notification->name === Notification::BEATMAPSET_DISQUALIFY
                    && in_array($this->user->getKey(), $event->getReceiverIds(), true);
            });
        } else {
            // We want to assert the job was queued but because there should be no receivers, there won't be a notification generated.
            Queue::assertPushed(BeatmapsetDisqualify::class);

            $this->runFakeQueue();

            Event::assertNotDispatched(NewPrivateNotificationEvent::class, function (NewPrivateNotificationEvent $event) {
                return $event->notification->name === Notification::BEATMAPSET_DISQUALIFY;
            });
        }
    }

    public function testNotificationSentIfNotificationOptionsEnabled()
    {
        $this->createNotificationOption();

        $this->disqualify()->assertStatus(200);

        Queue::assertPushed(BeatmapsetDisqualify::class);

        $this->runFakeQueue();

        Event::assertDispatched(NewPrivateNotificationEvent::class, function (NewPrivateNotificationEvent $event) {
            return $event->notification->name === Notification::BEATMAPSET_DISQUALIFY
                && in_array($this->user->getKey(), $event->getReceiverIds(), true);
        });
    }

    public function testNotificationNotSentIfNotificationOptionsNotEnabled()
    {
        $this->disqualify()->assertStatus(200);

        Queue::assertPushed(BeatmapsetDisqualify::class);

        $this->runFakeQueue();

        Event::assertNotDispatched(NewPrivateNotificationEvent::class);
    }
    #endregion

    #region event logging tests
    public function testDisqualifiedEventLogged()
    {
        $modes = $this->beatmapset->beatmaps->map->mode->all();
        $nominatorCount = config('osu.beatmapset.required_nominations');
        $nominators = [];
        for ($i = 0; $i < $nominatorCount; $i++) {
            $nominators[] = $nominator = $this->createUserWithGroupPlaymodes('bng', $modes);
            $this->beatmapset->events()->create([
                'type' => BeatmapsetEvent::NOMINATE,
                'user_id' => $nominator->getKey(),
            ]);
        }

        $disqualifyCount = BeatmapsetEvent::disqualifications()->count();
        $nominationResetReceivedCount = BeatmapsetEvent::nominationResetReceiveds()->count();

        $this->disqualify()->assertStatus(200);

        $this->assertSame($disqualifyCount + 1, BeatmapsetEvent::disqualifications()->count());
        $this->assertSame($nominationResetReceivedCount + $nominatorCount, BeatmapsetEvent::nominationResetReceiveds()->count());
        $this->assertEqualsCanonicalizing(array_pluck($nominators, 'user_id'), BeatmapsetEvent::nominationResetReceiveds()->pluck('user_id')->all());
    }
    #endregion

    public function booleanDataProvider()
    {
        return [
            [true],
            [false],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake();

        $owner = factory(User::class)->create();
        $this->beatmapset = factory(Beatmapset::class)->states('qualified', 'with_discussion')->create([
            'creator' => $owner->username,
            'user_id' => $owner->getKey(),
        ]);
        $this->sender = $this->createUserWithGroup('bng');
        $this->user = factory(User::class)->create();
    }

    private function createNotificationOption()
    {
        $this->user->notificationOptions()->create([
            'name' => Notification::BEATMAPSET_DISQUALIFY,
        ])->update(['details' => ['modes' => array_keys(Beatmap::MODES)]]);
    }

    private function disqualify()
    {
        return $this
            ->actingAsVerified($this->sender)
            ->post(route('beatmapsets.discussions.posts.store'), [
                'beatmapset_id' => $this->beatmapset->beatmapset_id,
                'beatmap_discussion' => [
                    'message_type' => 'problem',
                ],
                'beatmap_discussion_post' => [
                    'message' => 'Hello',
                ],
            ]);
    }
}
