<?php
class ProviderException extends Exception {
    public string $rawDetails;

    public function __construct(string $friendlyMessage, string $rawDetails = '') {
        parent::__construct($friendlyMessage);
        $this->rawDetails = $rawDetails;
    }

    /**
     * Maps an HTTP status code (or 0 for a network-level failure) to a
     * plain-English message, keeping the raw API response available
     * separately for anyone who wants to actually debug it.
     */
    public static function fromHttpResponse(int $httpCode, string $rawResponse, string $providerLabel): self {
        $friendly = match (true) {
            $httpCode === 401 || $httpCode === 403 =>
                "The API key for {$providerLabel} looks invalid, missing, or unauthorized. Check config.php.",
            $httpCode === 429 =>
                "{$providerLabel} is rate-limited or its free quota is used up right now. Try again shortly, or switch providers from the dropdown.",
            $httpCode >= 500 =>
                "{$providerLabel} is having server issues right now. Try again shortly.",
            $httpCode === 0 =>
                "Could not reach {$providerLabel} — check network/egress settings.",
            default =>
                "{$providerLabel} returned an unexpected error (HTTP {$httpCode}).",
        };
        return new self($friendly, $rawResponse);
    }
}
