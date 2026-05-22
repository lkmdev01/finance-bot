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

    public function test_parses_simple_tomorrow_reminder()
    {
        $result = $this->parser->parse('me lembra amanhã de falar com João');

        $this->assertNotNull($result);
        $this->assertEquals('Falar Com João', $result['title']);
        $this->assertEquals('once', $result['frequency']);
    }

    public function test_parses_daily_reminder_with_time()
    {
        $result = $this->parser->parse('me lembra todo dia as 14:30 de tomar água');

        $this->assertNotNull($result);
        $this->assertEquals('Tomar Água', $result['title']);
        $this->assertEquals('daily', $result['frequency']);
        $this->assertEquals('14:30:00', $result['trigger_time']);
    }

    public function test_parses_weekly_reminder()
    {
        $result = $this->parser->parse('me lembra toda segunda-feira de fazer reunião');

        $this->assertNotNull($result);
        $this->assertEquals('Fazer Reunião', $result['title']);
        $this->assertEquals('weekly', $result['frequency']);
        $this->assertEquals(1, $result['day_of_week']); // segunda = 1
    }

    public function test_parses_monthly_reminder()
    {
        $result = $this->parser->parse('me lembra todo mês dia 5 de pagar a conta');

        $this->assertNotNull($result);
        $this->assertEquals('Pagar A Conta', $result['title']);
        $this->assertEquals('monthly', $result['frequency']);
        $this->assertEquals(5, $result['day_of_month']);
    }

    public function test_parses_yearly_reminder_anniversary()
    {
        $result = $this->parser->parse('me lembra anualmente dia 10 de junho do aniversário de Maria');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Maria', $result['title']);
        $this->assertEquals('yearly', $result['frequency']);
        $this->assertEquals(10, $result['day_of_month']);
        $this->assertEquals(6, $result['month_of_year']);
    }

    public function test_parses_reminder_in_days()
    {
        $result = $this->parser->parse('me lembra em 3 dias de ligar para João');

        $this->assertNotNull($result);
        $this->assertEquals('Ligar Para João', $result['title']);
        $this->assertEquals('once', $result['frequency']);
    }

    public function test_uses_default_time_when_not_specified()
    {
        $result = $this->parser->parse('me lembra todo dia de fazer exercício');

        $this->assertNotNull($result);
        $this->assertEquals('09:00:00', $result['trigger_time']);
    }

    public function test_detects_anniversary_template()
    {
        $type = ReminderMessageTemplateFactory::detect('Aniversário de Maria', '');

        $this->assertEquals('anniversary', $type);
    }

    public function test_detects_payment_template()
    {
        $type = ReminderMessageTemplateFactory::detect('Pagar conta de água', '');

        $this->assertEquals('payment', $type);
    }

    public function test_detects_meeting_template()
    {
        $type = ReminderMessageTemplateFactory::detect('Reunião com time de desenvolvimento', '');

        $this->assertEquals('meeting', $type);
    }

    public function test_builds_friendly_anniversary_message()
    {
        $message = ReminderMessageTemplateFactory::buildFriendlyMessage(
            'Aniversário de Maria',
            'yearly',
            'anniversary'
        );

        $this->assertStringContainsString('Maria', $message);
        $this->assertStringContainsString('🎉', $message);
        $this->assertStringContainsString('parabens', strtolower($message));
    }

    public function test_builds_friendly_payment_message()
    {
        $message = ReminderMessageTemplateFactory::buildFriendlyMessage(
            'Pagar fatura do cartão',
            'monthly',
            'payment'
        );

        $this->assertStringContainsString('💰', $message);
        $this->assertStringContainsString('Pagar', $message);
    }

    public function test_title_cleanup_removes_date_patterns()
    {
        $result = $this->parser->parse('me lembra dia 15 do mes de outubro de fazer backup');

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('dia', strtolower($result['title']));
        $this->assertStringNotContainsString('outubro', strtolower($result['title']));
    }

    public function test_handles_portuguese_month_names()
    {
        $result = $this->parser->parse('me lembra anualmente dia 25 de dezembro de comprar presentes');

        $this->assertNotNull($result);
        $this->assertEquals('yearly', $result['frequency']);
        $this->assertEquals(12, $result['month_of_year']);
    }

    public function test_rejects_invalid_day_of_month()
    {
        $result = $this->parser->parse('me lembra todo mês dia 99 de fazer algo');

        // Deve normalizar para max 31
        $this->assertNotNull($result);
        $this->assertLessThanOrEqual(31, $result['day_of_month']);
    }

    public function test_partial_parse_allows_follow_up()
    {
        $partial = $this->parser->parsePartialCreate('me lembra diário de tomar medicamento');

        $this->assertNotNull($partial);
        $this->assertEquals('daily', $partial['frequency']);
        $this->assertNull($partial['day_of_week']);
    }

    public function test_uses_default_timezone()
    {
        $result = $this->parser->parse('me lembra amanhã às 14h de fazer compras');

        $this->assertNotNull($result);
        $this->assertNotNull($result['next_trigger_at']);
    }
}
