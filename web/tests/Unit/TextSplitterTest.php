<?php

namespace Tests\Unit;

use App\Services\TextSplitter;
use PHPUnit\Framework\TestCase;

class TextSplitterTest extends TestCase
{
    public function test_keeps_every_word_within_the_limit(): void
    {
        $text = str_repeat('Bonjour à tous, voici une phrase. ', 40)."\n\n".str_repeat('Une phrase, très longue, sans point final ', 30)
            ."\n".str_repeat('é', 250);

        $segments = (new TextSplitter)->split($text, 100);

        foreach ($segments as $segment) {
            $this->assertLessThanOrEqual(100, mb_strlen($segment['text']));
            $this->assertNotSame('', $segment['text']);
        }
        $joined = implode('', array_column($segments, 'text'));
        $this->assertSame(preg_replace('/\s+/u', '', $text), preg_replace('/\s+/u', '', $joined));
        $this->assertSame(3, count(array_filter(array_column($segments, 'ends_paragraph'))));
        $this->assertTrue(end($segments)['ends_paragraph']);
    }

    public function test_blank_text_gives_nothing(): void
    {
        $this->assertSame([], (new TextSplitter)->split(" \n\t\n ", 100));
    }
}
