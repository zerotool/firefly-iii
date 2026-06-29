<?php

declare(strict_types=1);

namespace FireflyIII\Services\Hermes;

class ResolvedFinanceIntent
{
    /** @var array */
    private $input;

    /** @var array */
    private $entities = [];

    /** @var array */
    private $missing = [];

    /** @var array */
    private $ambiguous = [];

    public function __construct(array $input)
    {
        $this->input = $input;
    }

    public function addEntity(string $key, array $candidates): void
    {
        $candidates = array_values($candidates);
        $selected   = 1 === \count($candidates) ? $candidates[0] : null;

        $this->entities[$key] = [
            'selected'   => $selected,
            'candidates' => $candidates,
        ];

        if ([] === $candidates) {
            $this->missing[] = $key;
        }

        if (\count($candidates) > 1) {
            $this->ambiguous[] = $key;
        }
    }

    public function addMissing(string $key): void
    {
        if (!\in_array($key, $this->missing, true)) {
            $this->missing[] = $key;
        }
    }

    public function addAmbiguous(string $key): void
    {
        if (!\in_array($key, $this->ambiguous, true)) {
            $this->ambiguous[] = $key;
        }
    }

    public function toArray(): array
    {
        return [
            'input'                 => $this->input,
            'entities'              => $this->entities,
            'missing'               => array_values(array_unique($this->missing)),
            'ambiguous'             => array_values(array_unique($this->ambiguous)),
            'requires_confirmation' => [] !== $this->missing || [] !== $this->ambiguous,
        ];
    }
}
