<?php
declare(strict_types=1);

final class AffiliateEngine
{
    public function __construct(private readonly Database $db) {}

    public function transformForUser(int $userId, string $text): string
    {
        $urls = UrlTools::extractUrls($text);

        foreach ($urls as $original) {
            $platform = UrlTools::platform($original);
            if ($platform === null) {
                continue;
            }

            $account = $this->db->fetch(
                "SELECT * FROM affiliate_accounts
                 WHERE user_id = ? AND platform = ? AND active = 1 LIMIT 1",
                [$userId, $platform]
            );

            if (!$account) {
                continue;
            }

            $resolved = $platform === 'shopee' ? $original : UrlTools::resolve($original);
            $affiliate = $this->build($platform, $account, $resolved);

            if ($affiliate !== null && $affiliate !== '') {
                $text = UrlTools::replaceUrl($text, $original, $affiliate);
            }
        }

        return $text;
    }

    private function build(string $platform, array $account, string $url): ?string
    {
        $id = trim((string)$account['affiliate_id']);
        $template = trim((string)$account['link_template']);

        $templated = UrlTools::applyTemplate($template, $url, $id);
        if ($templated !== null) {
            return $templated;
        }

        /*
         * Fallback configurável.
         *
         * ATENÇÃO:
         * parâmetros genéricos não são uma garantia de atribuição de comissão.
         * O cliente deve usar o mecanismo de geração de link aceito pelo
         * programa de afiliados correspondente.
         */
        return match ($platform) {
            'mercadolivre' => $id !== '' ? UrlTools::appendQuery($url, ['af_id' => $id]) : $url,
            'shopee' => $id !== '' ? UrlTools::appendQuery($url, ['utm_campaign' => $id]) : $url,
            'magalu' => $id !== '' ? UrlTools::appendQuery($url, ['affiliate_id' => $id]) : $url,
            default => $url,
        };
    }
}
