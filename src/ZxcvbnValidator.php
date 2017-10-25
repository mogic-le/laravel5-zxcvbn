<?php

namespace REBELinBLUE\Zxcvbn;

use Illuminate\Translation\Translator;
use InvalidArgumentException;
use ZxcvbnPhp\Zxcvbn;

/**
 * Class for validating passwords using Dropbox's Zxcvbn library.
 */
class ZxcvbnValidator
{
    const DEFAULT_MINIMUM_STRENGTH = 3;

    /** @var array|null */
    private $result;

    /** @var int */
    private $strength = 0;

    /** @var Translator */
    private $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    public function validate(...$args)
    {
        $value = trim($args[1]);
        $parameters = $args[2] ? $args[2] : [];

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = $args[3];
        $otherInput = []; // FIXME: Get this

        $desiredScore = isset($parameters[0]) ? $parameters[0] : self::DEFAULT_MINIMUM_STRENGTH;

        if ($desiredScore < 0 || $desiredScore > 4 || !is_numeric($desiredScore)) {
            throw new InvalidArgumentException('The required password score must be between 0 and 4');
        }

        $zxcvbn = new Zxcvbn();
        $strength = $zxcvbn->passwordStrength($value, $otherInput);

        $this->strength = $strength['score'];
        $this->result = $strength;

        if ($strength['score'] >= $desiredScore) {
            return true;
        }

        // FIXME: Is this the best way to do it?
        $validator->setCustomMessages([
            'zxcvbn' => $this->translator->get('zxcvbn::validation.' . $this->getFeedbackTranslation())
        ]);

        return false;
    }

    private function getFeedbackTranslation()
    {
        $isOnlyMatch = count($this->result['match_sequence']) === 1;

        $longestMatch = new \stdClass();
        $longestMatch->token = '';

        foreach ($this->result['match_sequence'] as $match) {
            if (strlen($match->token) > strlen($longestMatch->token) &&
                preg_match('/Match$/', get_class($match)) // FIXME: HORRIBLE, BECAUSE OF THE BRUTE FORCE MATCHER
            ) {
                $longestMatch = $match;
            }
        }

        return $this->getMatchFeedback($longestMatch, $isOnlyMatch);
    }

    private function getMatchFeedback($match, $isOnlyMatch)
    {
        $strategy = 'get' . ucfirst($match->pattern) . 'Warning';

        if (method_exists($this, $strategy)) {
            return $this->$strategy($match, $isOnlyMatch);
        }

        // FIXME: This should not happen
        return $strategy;
    }

    private function getDictionaryWarning($match, $isOnlyMatch)
    {
        $warning = ''; // FIXME: Should it be possible for this to happen?
        if ($match->dictionaryName == 'passwords') {
            $warning = $this->getPasswordWarning($match, $isOnlyMatch);
        } elseif ($match->dictionaryName == 'english') {
            $warning = 'common';
        } elseif (in_array($match->dictionaryName, ['surnames', 'male_names', 'female_names'])) {
            $warning = 'names';
        }

        if (isset($match->l33t)) {
            $warning = 'predictable';
        }

        return $warning;
    }

    private function getPasswordWarning($match, $isOnlyMatch)
    {
        $warning = 'common';
        if ($isOnlyMatch && !isset($match->l33t) && !isset($match->reversed)) {
            $warning = 'very_common';

            if ($match->rank <= 10) {
                $warning = 'top_10';
            } elseif ($match->rank <= 100) {
                $warning = 'top_100';
            }
        }

        return $warning;
    }

    private function getSequenceWarning()
    {
        return 'sequence';
    }

    private function getSpatialWarning($match)
    {
        $translation = 'spatial_with_turns';
        if ($match->turns === 1) {
            $translation = 'straight_spatial';
        }

        return $translation;
    }

    private function getRepeatWarning()
    {
        return 'repeat';
    }

    private function getDateWarning()
    {
        return 'dates';
    }

    private function getYearWarning()
    {
        return 'years';
    }

    private function getDigitWarning()
    {
        return 'digits';
    }
}