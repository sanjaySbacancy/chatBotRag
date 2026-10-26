<?php

namespace Tests\Unit\Answer;

use App\Services\Answer\SmallTalk;
use PHPUnit\Framework\TestCase;

class SmallTalkTest extends TestCase
{
    public function test_matches_whole_message_greetings_thanks_and_goodbyes(): void
    {
        $talk = new SmallTalk;

        $this->assertNotNull($talk->reply('Hi!'));
        $this->assertNotNull($talk->reply('Thank you.'));
        $this->assertNotNull($talk->reply('bye'));
        $this->assertNotNull($talk->reply('What can you do?'));
    }

    public function test_a_greeting_plus_a_real_question_is_not_small_talk(): void
    {
        $this->assertNull((new SmallTalk)->reply('hi, what is the leave policy?'));
    }
}
