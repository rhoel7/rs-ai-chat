<?php
interface Embedder {
    /** Returns a flat array of floats representing the embedding vector for $text. */
    public function embed(string $text): array;

    /** A short label identifying which provider produced the vector — stored alongside it, since vector spaces aren't compatible across providers. */
    public function getProviderLabel(): string;
}
