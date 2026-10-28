<?php

declare(strict_types=1);

/*
 * Model family presets used when importing upstream model lists that carry no
 * capability metadata (only OpenRouter's catalog does). First matching rule
 * wins; patterns are case-insensitive regular expressions on the upstream id.
 *
 * capability   routing capability (models.capability)
 * tags         extra capability_tags (a vision model also serves text)
 * input        input modalities for the upstream capability layer
 *
 * Edit this list to teach TokenHub new model families without a code change.
 * Last reviewed: 2026-10.
 */
return [
    'rules' => [
        // rerank and moderation before embeddings: "bge-reranker" must not match "bge-"
        ['pattern' => 'rerank', 'capability' => 'rerank'],
        ['pattern' => 'moderation', 'capability' => 'moderation'],
        // embeddings first among the rest: "qwen3-embedding" must not match qwen chat rules
        ['pattern' => '(embed|bge-|\be5-|gte-|jina-embeddings|voyage-)', 'capability' => 'embedding'],

        // realtime speech-to-speech (WebSocket, served by realtime-gateway)
        ['pattern' => '(realtime|-live$|-live-|native-audio)', 'capability' => 'realtime'],

        // speech
        ['pattern' => '(whisper|transcribe|\basr\b|-asr|paraformer|sensevoice|telespeech|fun-asr|scribe|nova-[23]|chirp|universal-[23])', 'capability' => 'asr'],
        ['pattern' => '(\btts\b|-tts|tts-|speech-0|speech-2|cosyvoice|eleven_|sonic-|seed-tts|fish-|s2(\.1)?-pro|text-to-speech)', 'capability' => 'tts'],

        // music (before generic image/video rules)
        ['pattern' => '(^|/)(music[-_]|lyria|stable-audio|suno)', 'capability' => 'music_generation'],

        // video generation
        ['pattern' => '(seedance|kling|hailuo|minimax-h3|video-0|wan[0-9.]*-(t2v|i2v|video|kf2v|s2v|r2v)|wan[0-9.]+-video|vidu|cogvideo|\bveo|sora|\bray-|gen-?4|aleph|pixverse|grok-imagine-video|hunyuan-video|omni-video|video-generation|t2v|i2v)', 'capability' => 'video_generation'],

        // image generation
        ['pattern' => '(seedream|gpt-image|dall-e|imagen|nano-banana|-image(-|$)|flux|ideogram|recraft|kolors|cogview|glm-image|qwen-image|wan[0-9.]*-(t2i|image)|hunyuan-image|image-01|stable-diffusion|sd3|grok-imagine-image|midjourney)', 'capability' => 'image_generation'],

        // chat models that also take video (video_url parts) / audio (input_audio parts)
        ['pattern' => '(qwen[0-9.]*-vl|qwen[0-9.]*-omni|doubao-seed|glm-[0-9.]+v)', 'capability' => 'video_understanding', 'tags' => ['text_generation', 'vision_understanding'], 'input' => ['text', 'image', 'video']],
        ['pattern' => '(gpt-4o-audio|gpt-audio|audio-preview)', 'capability' => 'audio_understanding', 'tags' => ['text_generation'], 'input' => ['text', 'audio']],

        // multimodal chat models (vision understanding also serves text)
        ['pattern' => '(kimi-k2\.6|kimi-k3|(^|/)k3(\.|-|$)|deepseek-flash|deepseek-v4\.1|deepseek-v4-flash|-vl\b|-vl-|qwen[0-9.]*-vl|qvq|qwen[0-9.]*-omni|glm-[0-9.]+v|-vision|llava|pixtral|gemini|claude|gpt-4o|gpt-4\.1|gpt-5|gpt-6|grok-4|mistral-large-4|doubao-seed|mimo-v|step-[0-9]+v)', 'capability' => 'vision_understanding', 'tags' => ['text_generation'], 'input' => ['text', 'image']],
    ],
];
