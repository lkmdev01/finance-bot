<?php

namespace App\Services\WhatsApp;

use App\Jobs\RunWebResearch;
use App\Models\ResearchQuery;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AiUserTokenBudget;
use Illuminate\Support\Str;

class ResearchCommandService
{
    public function __construct(private readonly AiUserTokenBudget $tokenBudget) {}

    public function handle(string $message, User $user, WhatsAppContact $contact): ?string
    {
        $normalized = Str::of($message)->ascii()->lower()->squish()->toString();
        if (! $this->looksLikeResearchRequest($normalized)) {
            return null;
        }

        if ($this->isCapabilityQuestion($normalized)) {
            return config('services.searxng.enabled') && config('services.searxng.base_url')
                ? 'Sim. Posso pesquisar informações públicas na internet e trazer as fontes para você conferir.'
                : 'Sim. A função Pesquisas já está integrada ao aplicativo, mas está desativada nesta instalação. Quando for habilitada, vou consultar fontes públicas e trazê-las junto com a resposta.';
        }

        if (! config('services.searxng.enabled') || ! config('services.searxng.base_url')) {
            return 'A mesa Pesquisas ainda não está habilitada nesta versão. O pedido não foi enviado a nenhum serviço externo.';
        }

        if ($this->containsPrivateAccountRequest($normalized)) {
            return 'Pesquisas usam apenas informações públicas. Não envio saldo, documentos, contatos ou outros dados da sua conta para a internet. Posso pesquisar um tema público se você reformular o pedido.';
        }

        if ($this->tokenBudget->hasReachedLimit($user)) {
            $limit = max(0, (int) config('ai.user_monthly_token_limit', 20000));

            return 'Você atingiu o limite mensal de '.number_format($limit, 0, ',', '.').' tokens de IA. A pesquisa não foi enviada ao provedor externo.';
        }

        $query = $this->extractQuery($message);
        $query = $this->removePrivateIdentifiers($query, $user);
        if (mb_strlen($query) < 4) {
            return 'O que você quer que eu pesquise? Pode dizer o produto, assunto ou comparação.';
        }

        $record = ResearchQuery::query()->create([
            'user_id' => $user->id,
            'whatsapp_contact_id' => $contact->id,
            'query' => mb_substr(trim($message), 0, 1000),
            'search_query' => mb_substr($query, 0, 300),
            'status' => 'pending',
        ]);
        RunWebResearch::dispatch($record->id);

        return 'Recebi sua pesquisa e vou consultar fontes públicas. Envio o resultado aqui assim que estiver pronto. O histórico fica na mesa Pesquisas do painel.';
    }

    public function looksLikeResearchRequest(string $normalized): bool
    {
        return preg_match('/^italo\b/u', $normalized) === 1
            || preg_match('/\b(pesquisa|pesquise|pesquisar|pesquisas|na internet|cotacao do|dolar hoje|preco medio|avaliacao de (?:um )?(?:lugar|restaurante|hotel)|compare (?:as? )?opcoes)\b/u', $normalized) === 1
            || preg_match('/\bquanto (?:ta|esta) (?:o )?(?:dolar|euro|bitcoin)\b/u', $normalized) === 1;
    }

    private function isCapabilityQuestion(string $message): bool
    {
        return preg_match('/\b(voce consegue|da para|pode|sabe)\b.{0,30}\bpesquis(a|ar)\b/u', $message) === 1;
    }

    private function containsPrivateAccountRequest(string $message): bool
    {
        return preg_match('/\b(meu|minha|meus|minhas)\b.{0,35}\b(saldo|gastos?|despesas?|receitas?|renda|contas?|cartoes?|documentos?|telefone|email|e-mail|cpf|cnpj|extratos?|financas?)\b/u', $message) === 1;
    }

    private function extractQuery(string $message): string
    {
        $query = preg_replace('/^\s*(?:i|í)talo[, :!-]*\s*/iu', '', trim($message));
        $query = preg_replace('/^\s*(?:pesquis(?:a|e|ar)|faz(?:er)? pesquisa(?:r)?|procure)\s*/iu', '', (string) $query);
        $query = preg_replace('/\b(por favor|na internet|na web)\b/iu', '', (string) $query);

        return trim(preg_replace('/\s+/u', ' ', (string) $query));
    }

    private function removePrivateIdentifiers(string $query, User $user): string
    {
        $query = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[removido]', $query);
        $query = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/u', '[documento removido]', (string) $query);
        $query = preg_replace('/\b\+?\d[\d ()-]{8,}\d\b/u', '[contato removido]', (string) $query);
        $name = trim((string) $user->name);
        if ($name !== '') {
            $query = preg_replace('/'.preg_quote($name, '/').'/iu', '[nome removido]', (string) $query);
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $query));
    }
}
