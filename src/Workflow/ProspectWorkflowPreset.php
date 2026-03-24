<?php

declare(strict_types=1);

namespace Claudriel\Workflow;

use Waaseyaa\Workflows\Workflow;

/**
 * Factory for the prospect pipeline workflow preset.
 *
 * Creates a Workflow config entity pre-populated with 7 prospect
 * states (lead, qualified, contacted, proposal, negotiation, won, lost)
 * and 7 transitions modeling a sales pipeline lifecycle.
 *
 * No guard methods are needed; all transitions are unconditional.
 */
final class ProspectWorkflowPreset
{
    public const string STATE_LEAD = 'lead';

    public const string STATE_QUALIFIED = 'qualified';

    public const string STATE_CONTACTED = 'contacted';

    public const string STATE_PROPOSAL = 'proposal';

    public const string STATE_NEGOTIATION = 'negotiation';

    public const string STATE_WON = 'won';

    public const string STATE_LOST = 'lost';

    /** All valid workflow states. */
    public const array VALID_STATES = [
        self::STATE_LEAD,
        self::STATE_QUALIFIED,
        self::STATE_CONTACTED,
        self::STATE_PROPOSAL,
        self::STATE_NEGOTIATION,
        self::STATE_WON,
        self::STATE_LOST,
    ];

    public static function create(): Workflow
    {
        return new Workflow([
            'id' => 'prospect',
            'label' => 'Prospect',
            'states' => [
                'lead' => ['label' => 'Lead', 'weight' => 0],
                'qualified' => ['label' => 'Qualified', 'weight' => 1],
                'contacted' => ['label' => 'Contacted', 'weight' => 2],
                'proposal' => ['label' => 'Proposal', 'weight' => 3],
                'negotiation' => ['label' => 'Negotiation', 'weight' => 4],
                'won' => ['label' => 'Won', 'weight' => 5],
                'lost' => ['label' => 'Lost', 'weight' => 6],
            ],
            'transitions' => [
                'qualify' => [
                    'label' => 'Qualify',
                    'from' => ['lead'],
                    'to' => 'qualified',
                ],
                'contact' => [
                    'label' => 'Contact',
                    'from' => ['qualified'],
                    'to' => 'contacted',
                ],
                'propose' => [
                    'label' => 'Propose',
                    'from' => ['contacted'],
                    'to' => 'proposal',
                ],
                'negotiate' => [
                    'label' => 'Negotiate',
                    'from' => ['proposal'],
                    'to' => 'negotiation',
                ],
                'win' => [
                    'label' => 'Win',
                    'from' => ['negotiation'],
                    'to' => 'won',
                ],
                'lose' => [
                    'label' => 'Lose',
                    'from' => ['lead', 'qualified', 'contacted', 'proposal', 'negotiation'],
                    'to' => 'lost',
                ],
                'reopen' => [
                    'label' => 'Reopen',
                    'from' => ['lost'],
                    'to' => 'lead',
                ],
            ],
        ]);
    }
}
