package main

import "encoding/json"

// usageTotals is the usage of one completed response (or one Gemini turn),
// in TokenHub's canonical names.
type usageTotals struct {
	InputTokens       int `json:"input_tokens"`
	OutputTokens      int `json:"output_tokens"`
	CacheReadTokens   int `json:"cache_read_tokens"`
	AudioInputTokens  int `json:"audio_input_tokens"`
	AudioOutputTokens int `json:"audio_output_tokens"`
	ImageInputTokens  int `json:"image_input_tokens"`
	AudioSeconds      int `json:"audio_seconds"`
}

func (u usageTotals) empty() bool {
	return u == usageTotals{}
}

// usageParser watches upstream server events.
//
//	openai: "response.done" carries response.usage; input audio
//	        transcription events carry their own usage.
//	gemini: "usageMetadata" snapshots, reported when "turnComplete" arrives.
type usageParser struct {
	protocol string
	pending  usageTotals
	hasTurn  bool
}

func newUsageParser(protocol string) *usageParser {
	if protocol == "" {
		protocol = "openai"
	}
	return &usageParser{protocol: protocol}
}

type openAIDetails struct {
	CachedTokens int `json:"cached_tokens"`
	AudioTokens  int `json:"audio_tokens"`
	ImageTokens  int `json:"image_tokens"`
}

type openAIUsage struct {
	Type               string        `json:"type"`
	InputTokens        int           `json:"input_tokens"`
	OutputTokens       int           `json:"output_tokens"`
	Seconds            float64       `json:"seconds"`
	InputTokenDetails  openAIDetails `json:"input_token_details"`
	OutputTokenDetails openAIDetails `json:"output_token_details"`
}

type geminiModality struct {
	Modality   string `json:"modality"`
	TokenCount int    `json:"tokenCount"`
}

type geminiUsage struct {
	PromptTokenCount     int              `json:"promptTokenCount"`
	ResponseTokenCount   int              `json:"responseTokenCount"`
	CandidatesTokenCount int              `json:"candidatesTokenCount"`
	ThoughtsTokenCount   int              `json:"thoughtsTokenCount"`
	CachedContentTokens  int              `json:"cachedContentTokenCount"`
	PromptTokensDetails  []geminiModality `json:"promptTokensDetails"`
	ResponseTokenDetails []geminiModality `json:"responseTokensDetails"`
}

// observe returns usage to report now, if this event completes a response.
func (p *usageParser) observe(message []byte) (usageTotals, bool) {
	if p.protocol == "gemini" {
		return p.observeGemini(message)
	}
	var event struct {
		Type     string       `json:"type"`
		Usage    *openAIUsage `json:"usage"`
		Response struct {
			Usage *openAIUsage `json:"usage"`
		} `json:"response"`
	}
	if json.Unmarshal(message, &event) != nil {
		return usageTotals{}, false
	}
	var usage *openAIUsage
	switch {
	case event.Type == "response.done":
		usage = event.Response.Usage
	case event.Usage != nil && len(event.Type) > 0 && hasSuffix(event.Type, "transcription.completed"):
		usage = event.Usage
	}
	if usage == nil {
		return usageTotals{}, false
	}
	totals := usageTotals{
		InputTokens:       usage.InputTokens,
		OutputTokens:      usage.OutputTokens,
		CacheReadTokens:   usage.InputTokenDetails.CachedTokens,
		AudioInputTokens:  usage.InputTokenDetails.AudioTokens,
		AudioOutputTokens: usage.OutputTokenDetails.AudioTokens,
		ImageInputTokens:  usage.InputTokenDetails.ImageTokens,
	}
	if usage.Type == "duration" && usage.Seconds > 0 {
		totals.AudioSeconds = int(usage.Seconds + 0.999)
	}
	return totals, !totals.empty()
}

func (p *usageParser) observeGemini(message []byte) (usageTotals, bool) {
	var event struct {
		UsageMetadata *geminiUsage `json:"usageMetadata"`
		ServerContent struct {
			TurnComplete bool `json:"turnComplete"`
		} `json:"serverContent"`
	}
	if json.Unmarshal(message, &event) != nil {
		return usageTotals{}, false
	}
	if u := event.UsageMetadata; u != nil {
		output := u.ResponseTokenCount
		if output == 0 {
			output = u.CandidatesTokenCount
		}
		p.pending = usageTotals{
			InputTokens:       u.PromptTokenCount,
			OutputTokens:      output + u.ThoughtsTokenCount,
			CacheReadTokens:   u.CachedContentTokens,
			AudioInputTokens:  modalityTokens(u.PromptTokensDetails, "AUDIO"),
			AudioOutputTokens: modalityTokens(u.ResponseTokenDetails, "AUDIO"),
			ImageInputTokens:  modalityTokens(u.PromptTokensDetails, "IMAGE"),
		}
		p.hasTurn = true
	}
	if event.ServerContent.TurnComplete && p.hasTurn {
		totals := p.pending
		p.pending = usageTotals{}
		p.hasTurn = false
		return totals, !totals.empty()
	}
	return usageTotals{}, false
}

// flush returns usage observed but not yet reported (session ended mid-turn).
func (p *usageParser) flush() (usageTotals, bool) {
	if !p.hasTurn || p.pending.empty() {
		return usageTotals{}, false
	}
	totals := p.pending
	p.pending = usageTotals{}
	p.hasTurn = false
	return totals, true
}

func modalityTokens(details []geminiModality, modality string) int {
	sum := 0
	for _, d := range details {
		if d.Modality == modality {
			sum += d.TokenCount
		}
	}
	return sum
}

func hasSuffix(s, suffix string) bool {
	return len(s) >= len(suffix) && s[len(s)-len(suffix):] == suffix
}
