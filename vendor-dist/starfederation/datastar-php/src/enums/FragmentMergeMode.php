<?php

namespace HMApi\starfederation\datastar\enums;

/**
 * This is auto-generated by Datastar. DO NOT EDIT.
 */
enum FragmentMergeMode: string
{

    // Morphs the fragment into the existing element using idiomorph.
    case Morph = 'morph';

    // Replaces the inner HTML of the existing element.
    case Inner = 'inner';

    // Replaces the outer HTML of the existing element.
    case Outer = 'outer';

    // Prepends the fragment to the existing element.
    case Prepend = 'prepend';

    // Appends the fragment to the existing element.
    case Append = 'append';

    // Inserts the fragment before the existing element.
    case Before = 'before';

    // Inserts the fragment after the existing element.
    case After = 'after';

    // Upserts the attributes of the existing element.
    case UpsertAttributes = 'upsertAttributes';
}