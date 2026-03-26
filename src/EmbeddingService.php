<?php

namespace GlpiPlugin\Agentassistant;

/**
 * EmbeddingService — TF-IDF sparse vector embeddings.
 *
 * Approach (no external API required):
 *  1. Normalize text: lowercase, remove accents, strip HTML
 *  2. Tokenize: split on non-alphanumeric, remove stopwords
 *  3. Compute term-frequency vector (raw counts / total tokens)
 *  4. Multiply by pre-computed IDF weights (log-based approximation)
 *  5. Return sparse map: { term => float_weight }
 *
 * Cosine similarity on two such maps is used for ticket matching.
 */
class EmbeddingService
{
    // Top N terms to keep per document
    private const MAX_TERMS = 60;

    // ── Stopwords (PT + EN) ───────────────────────────────────────────────────

    private static array $stopwords = [
        // PT
        'a','ao','aos','aquela','aquelas','aquele','aqueles','aquilo','as','ate',
        'com','como','da','das','de','dela','delas','dele','deles','depois','do',
        'dos','e','ela','elas','ele','eles','em','entre','era','essa','esse',
        'esta','este','eu','foi','for','ha','isso','ja','lhe','lhes','lo','mas',
        'me','mesmo','meu','minha','muito','na','nao','nas','nem','no','nos',
        'nossa','nossas','nosso','nossos','num','numa','o','os','ou','para','pela',
        'pelas','pelo','pelos','por','qual','quando','que','quem','se','sem',
        'ser','seu','sua','suas','seus','so','tambem','te','tem','ter','teu',
        'tua','tuas','teus','toda','todas','todo','todos','tu','ua','um','uma',
        'umas','uns','vai','voce','voces','vos',
        // EN
        'a','an','and','are','as','at','be','been','being','but','by',
        'can','could','did','do','does','doing','done','for','from','get',
        'got','has','have','he','her','him','his','how','i','if','in',
        'into','is','it','its','me','more','my','not','of','on','or',
        'our','out','so','than','that','the','their','them','then','there',
        'they','this','to','up','us','was','we','were','what','when',
        'which','who','will','with','would','you','your',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Compute and store the embedding for a ticket (or update if outdated).
     *
     * @return string MD5 checksum of the source text
     */
    public static function embedTicket(int $ticketId): string
    {
        global $DB;

        $ticket = new \Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return '';
        }

        $text     = self::extractTicketText($ticket);
        $checksum = md5($text);

        // Skip if already up-to-date
        $existing = $DB->request([
            'SELECT' => ['id', 'checksum'],
            'FROM'   => 'glpi_plugin_agentassistant_embeddings',
            'WHERE'  => ['tickets_id' => $ticketId],
            'LIMIT'  => 1,
        ]);
        if ($existing->count() > 0 && $existing->current()['checksum'] === $checksum) {
            return $checksum;
        }

        $vector   = self::embed($text);
        $keywords = array_slice(array_keys($vector), 0, 10); // top-10 keywords

        $data = [
            'tickets_id'     => $ticketId,
            'checksum'       => $checksum,
            'embedding_json' => json_encode($vector),
            'keywords_json'  => json_encode($keywords),
        ];

        if ($existing->count() > 0) {
            $DB->update('glpi_plugin_agentassistant_embeddings', $data, ['tickets_id' => $ticketId]);
        } else {
            $DB->insert('glpi_plugin_agentassistant_embeddings', $data);
        }

        return $checksum;
    }

    /**
     * Compute a TF-IDF embedding vector for a raw text string.
     *
     * @return array<string,float>  term → weight, sorted DESC
     */
    public static function embed(string $text): array
    {
        $tokens = self::tokenize($text);
        if (empty($tokens)) {
            return [];
        }

        $total = count($tokens);
        $freq  = array_count_values($tokens);

        // TF = count / total tokens
        // IDF approximation: log(1 + 1/freq) — terms that appear rarely get higher IDF
        $vector = [];
        foreach ($freq as $term => $count) {
            $tf  = $count / $total;
            $idf = log(1 + 1 / $count);
            $vector[$term] = round($tf * $idf, 6);
        }

        // Sort by weight DESC, keep top MAX_TERMS
        arsort($vector);
        return array_slice($vector, 0, self::MAX_TERMS, true);
    }

    /**
     * Cosine similarity between two TF-IDF vectors.
     *
     * @param array<string,float> $a
     * @param array<string,float> $b
     * @return float  0.0 – 1.0
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }

        $dot  = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $term => $weight) {
            $dot   += $weight * ($b[$term] ?? 0.0);
            $normA += $weight * $weight;
        }
        foreach ($b as $weight) {
            $normB += $weight * $weight;
        }

        $denom = sqrt($normA) * sqrt($normB);
        return $denom > 0.0 ? min(1.0, $dot / $denom) : 0.0;
    }

    /**
     * Jaccard similarity on token sets (keyword fallback when vectors are sparse).
     *
     * @param string[] $a
     * @param string[] $b
     */
    public static function jaccardSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $setA         = array_unique($a);
        $setB         = array_unique($b);
        $intersection = count(array_intersect($setA, $setB));
        $union        = count(array_unique(array_merge($setA, $setB)));
        return $union > 0 ? $intersection / $union : 0.0;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function extractTicketText(\Ticket $ticket): string
    {
        $title   = $ticket->fields['name']    ?? '';
        $content = $ticket->fields['content'] ?? '';
        // strip HTML tags from content (GLPI stores rich text)
        $content = strip_tags($content);
        return trim($title . ' ' . $content);
    }

    /**
     * Tokenize: normalize, strip, split, filter stopwords, min length 3.
     *
     * @return string[]
     */
    public static function tokenize(string $text): array
    {
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');

        // Remove accents (NFD decomposition + strip combining marks)
        $text = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $text = preg_replace('/\p{Mn}/u', '', $text);

        // Strip HTML & digits-only tokens
        $text = strip_tags($text);

        // Split on any non-word character
        $tokens = preg_split('/[^\pL\pN]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }

        $stopwords = array_flip(self::$stopwords);
        $result    = [];
        foreach ($tokens as $token) {
            if (
                strlen($token) >= 3
                && !isset($stopwords[$token])
                && !is_numeric($token)
            ) {
                $result[] = $token;
            }
        }

        return $result;
    }
}
