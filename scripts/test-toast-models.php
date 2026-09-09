<?php
require dirname(__DIR__) . '/lib/toast-models.php';
function checkModel(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$groq = ['model' => 'legacy-text', 'vision_model' => 'legacy-image', 'website_model' => 'legacy-feed'];
foreach (TOAST_MODEL_SCENARIOS as $scenario) {
    $expected = str_starts_with($scenario, 'feed_') ? 'legacy-feed' : (str_ends_with($scenario, '_images') ? 'legacy-image' : 'legacy-text');
    checkModel(toast_model_for($groq, $scenario) === $expected, 'legacy fallback: ' . $scenario);
    $groq['models'][$scenario] = 'provider/' . $scenario;
}
$models = toast_validate_models($groq['models']);
foreach (TOAST_MODEL_SCENARIOS as $scenario) checkModel(toast_model_for($groq, $scenario) === 'provider/' . $scenario, 'scenario override: ' . $scenario);
checkModel(toast_model_for(['feed_model' => 'old-feed'], 'feed_drafts') === 'old-feed', 'feed fallback');
foreach ([[], ['unknown' => 'model'], array_merge($models, ['feed_drafts' => "bad\nmodel"]), array_merge($models, ['feed_drafts' => []])] as $invalid) {
    try { toast_validate_models($invalid); throw new RuntimeException('invalid settings accepted'); }
    catch (InvalidArgumentException $expected) {}
}

foreach (TOAST_MODEL_SCENARIOS as $scenario) {
    $resolved = toast_model_for(['models' => [$scenario => 'meta-llama/llama-4-scout-17b-16e-instruct']], $scenario);
    checkModel(!in_array($resolved, toast_model_policy()['retired'], true), 'retired override remained');
}
try { toast_validate_models(array_merge($models, ['discord_text' => 'llama-3.1-8b-instant'])); throw new RuntimeException('retired model accepted'); }
catch (InvalidArgumentException $expected) {}

echo "Toast model checks passed.\n";
