<?php

namespace Tests\Feature;

use App\Services\WhatsApp\ReminderMessageParser;
use App\Services\WhatsApp\ReminderMessageTemplateFactory;
use Tests\TestCase;

class ReminderParsingTest extends TestCase
{
    private ReminderMessageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(ReminderMessageParser::class);
    }

    public function test_parses_simple_tomorrow_reminder(): void
    {
        $result = $this->parser->parse('me lembra amanha de falar com Joao');

        $this->assertNotNull($result);
        $this->assertEquals('Falar Com Joao', $result['title']);
        $this->assertEquals('once', $result['frequency']);
    }

    public function test_parses_daily_reminder_with_time(): void
    {
        $result = $this->parser->parse('me lembra todo dia as 14:30 de tomar agua');

        $this->assertNotNull($result);
        $this->assertEquals('Tomar Agua', $result['title']);
        $this->assertEquals('daily', $result['frequency']);
        $this->assertEquals('14:30:00', $result['trigger_time']);
    }

    public function test_parses_weekly_reminder(): void
    {
        $result = $this->parser->parse('me lembra toda segunda-feira de fazer reuniao');

        $this->assertNotNull($result);
        $this->assertEquals('Fazer Reuniao', $result['title']);
        $this->assertEquals('weekly', $result['frequency']);
        $this->assertEquals(1, $result['day_of_week']); // segunda = 1
    }

    public function test_parses_monthly_reminder(): void
    {
        $result = $this->parser->parse('me lembra todo mes dia 5 de pagar a conta');

        $this->assertNotNull($result);
        $this->assertEquals('Pagar A Conta', $result['title']);
        $this->assertEquals('monthly', $result['frequency']);
        $this->assertEquals(5, $result['day_of_month']);
    }

    public function test_parses_yearly_reminder_anniversary(): void
    {
        $result = $this->parser->parse('me lembra anualmente dia 10 de junho do aniversario de Maria');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Maria', $result['title']);
        $this->assertEquals('yearly', $result['frequency']);
        $this->assertEquals(10, $result['day_of_month']);
        $this->assertEquals(6, $result['month_of_year']);
    }

    public function test_parses_reminder_in_days(): void
    {
        $result = $this->parser->parse('me lembra em 3 dias de ligar para Joao');

        $this->assertNotNull($result);
        $this->assertEquals('Ligar Para Joao', $result['title']);
        $this->assertEquals('once', $result['frequency']);
        $this->assertNotNull($result['next_trigger_at']);
    }

    public function test_uses_default_time_when_not_specified(): void
    {
        $result = $this->parser->parse('me lembra todo dia de fazer exercicio');

        $this->assertNotNull($result);
        $this->assertEquals('09:00:00', $result['trigger_time']);
    }

    public function test_detects_anniversary_template(): void
    {
        $type = ReminderMessageTemplateFactory::detect('Aniversario de Maria', '');

        $this->assertEquals('anniversary', $type);
    }

    public function test_detects_payment_template(): void
    {
        $type = ReminderMessageTemplateFactory::detect('Pagar conta de agua', '');

        $this->assertEquals('payment', $type);
    }

    public function test_detects_meeting_template(): void
    {
        $type = ReminderMessageTemplateFactory::detect('Reuniao com time de desenvolvimento', '');

        $this->assertEquals('meeting', $type);
    }

    public function test_builds_friendly_anniversary_message(): void
    {
        $message = ReminderMessageTemplateFactory::buildFriendlyMessage(
            'Aniversario de Maria',
            'yearly',
            'anniversary'
        );

        $this->assertStringContainsString('Maria', $message);
        $this->assertStringContainsString('parabens', strtolower($message));
    }

    public function test_builds_friendly_payment_message(): void
    {
        $message = ReminderMessageTemplateFactory::buildFriendlyMessage(
            'Pagar fatura do cartao',
            'monthly',
            'payment'
        );

        $this->assertStringContainsString('pagar', strtolower($message));
    }

    public function test_title_cleanup_removes_date_patterns(): void
    {
        $result = $this->parser->parse('me lembra dia 15 do mes de outubro de fazer backup');

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('dia', strtolower($result['title']));
        $this->assertStringNotContainsString('outubro', strtolower($result['title']));
    }

    public function test_handles_portuguese_month_names(): void
    {
        $result = $this->parser->parse('me lembra anualmente dia 25 de dezembro de comprar presentes');

        $this->assertNotNull($result);
        $this->assertEquals('yearly', $result['frequency']);
        $this->assertEquals(12, $result['month_of_year']);
    }

    public function test_rejects_invalid_day_of_month(): void
    {
        $result = $this->parser->parse('me lembra todo mes dia 99 de fazer algo');

        $this->assertNotNull($result);
        $this->assertLessThanOrEqual(31, $result['day_of_month']);
    }

    public function test_partial_parse_allows_follow_up(): void
    {
        $partial = $this->parser->parsePartialCreate('me lembra diario de tomar medicamento');

        $this->assertNotNull($partial);
        $this->assertEquals('daily', $partial['frequency']);
        $this->assertNull($partial['day_of_week']);
    }

    public function test_uses_default_timezone(): void
    {
        $result = $this->parser->parse('me lembra amanha as 14h de fazer compras');

        $this->assertNotNull($result);
        $this->assertNotNull($result['next_trigger_at']);
    }
}

