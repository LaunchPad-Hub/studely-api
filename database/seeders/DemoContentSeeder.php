<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\{
    Tenant, User, Student, Assessment, College, Module, Question, Option
};

class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // --- Tiny inline helpers (no Faker) ---
            $pick = fn(array $arr) => $arr[array_rand($arr)];
            $num  = fn(int $min, int $max) => random_int($min, $max);
            $digits = function (int $len) {
                $s = '';
                for ($i = 0; $i < $len; $i++) $s .= (string) random_int(0, 9);
                return $s;
            };

            $branches = ['Computer Science', 'Electronics', 'Business', 'Communication'];
            $cities   = ['Pune', 'Bangalore', 'Hyderabad', 'Mumbai', 'Delhi', 'Ahmedabad', 'Jaipur', 'Kolkata', 'Chennai'];

            // --- Tenant ---
            $tenant = Tenant::firstOrCreate(
                ['code' => 'demo'],
                ['name' => 'TechNirma Institute of Technology']
            );

            // --- Colleges ---
            $colleges = collect([
                ['name' => 'TechNirma College of Engineering',     'code' => 'ENG', 'location' => 'Pune',      'description' => 'Focuses on engineering and applied sciences.'],
                ['name' => 'Arya College of Management Studies',   'code' => 'MGT', 'location' => 'Bangalore', 'description' => 'Renowned for its business and management programs.'],
                ['name' => 'Nirma Institute of Computer Science',  'code' => 'CSE', 'location' => 'Hyderabad', 'description' => 'Specializes in software engineering and computing.'],
                ['name' => 'Vidya College of Communication',       'code' => 'COM', 'location' => 'Mumbai',    'description' => 'Dedicated to soft skills and public communication.'],
            ])->map(fn($data) => College::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $data['code']],
                $data
            ));

            // --- Admin user ---
            $admin = User::firstOrCreate(
                ['email' => 'admin@technirma.in'],
                [
                    'name'              => 'Institute Admin',
                    'tenant_id'         => $tenant->id,
                    'college_id'         => $colleges->random()->id,
                    'password'          => Hash::make('Password!234'),
                    'email_verified_at' => now(),
                    'registered_at'     => now(),
                ]
            );
            if (method_exists($admin, 'assignRole')) {
                $admin->assignRole('CollegeAdmin');
            }

            // --- Students ---
            $studentNames = [
                'Aarav Sharma', 'Diya Patel', 'Ishaan Mehta', 'Priya Nair', 'Rohan Iyer',
                'Ananya Reddy', 'Karan Gupta', 'Sneha Raj', 'Devansh Das', 'Meera Singh',
                'Kabir Bansal', 'Ritika Chopra', 'Aditi Pillai', 'Rahul Menon', 'Simran Deshmukh',
            ];

            foreach ($studentNames as $i => $name) {
                $email = strtolower(Str::slug($name, '.')) . '@technirma.in';

                $studentUser = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name'              => $name,
                        'password'          => Hash::make('Password!234'),
                        'tenant_id'         => $tenant->id,
                        'email_verified_at' => now(),
                        'registered_at'     => now(),
                    ]
                );

                if (method_exists($studentUser, 'assignRole')) {
                    $studentUser->assignRole('Student');
                }

                $college = $colleges->random();

                $dob = now()
                    ->clone()
                    ->subYears($num(19, 22))
                    ->subMonths($num(1, 11))
                    ->subDays($num(0, 27));

                Student::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'user_id'   => $studentUser->id,
                    ],
                    [
                        'college_id'        => $college->id,
                        'reg_no'            => 'TN-' . str_pad($i + 1, 3, '0', STR_PAD_LEFT),
                        'branch'            => $pick($branches),
                        'cohort'            => '2025',
                        'gender'            => $i % 2 === 0 ? 'Male' : 'Female',
                        'dob'               => $dob,
                        'admission_year'    => 2022,
                        'current_semester'  => $num(4, 6),
                        'meta'              => [
                            'phone'   => '+91' . $digits(10),
                            'address' => $pick($cities),
                        ],
                    ]
                );
            }

            /* -----------------------------------------------------------------
             |  ASSESSMENTS + MODULES + QUESTIONS
             |  Baseline + Final, each with ordered modules and questions.
             |  Modules have status: "unlocked" / "locked" for progression.
             * ---------------------------------------------------------------- */

            $assessmentDefs = [
                [
                    'title'       => 'Baseline Assessment',
                    'type'        => 'online',
                    'instructions'=> 'This baseline assessment measures the student’s current level across multiple categories before any training. Complete all modules in order.',
                    'total_marks' => 100,
                    'is_active'   => true,
                    'open_at'     => now()->clone()->subDays(7),
                    'close_at'    => now()->clone()->addDays(7),
                ],
                [
                    'title'       => 'Final Assessment',
                    'type'        => 'online',
                    'instructions'=> 'This final assessment compares performance after the training program. Complete all modules in order.',
                    'total_marks' => 100,
                    'is_active'   => true,
                    'open_at'     => now()->clone()->addDays(15),
                    'close_at'    => now()->clone()->addDays(30),
                ],
            ];

            // Shared module “templates” so Baseline/Final mirror each other
            $moduleTemplates = [
                [
                    'title'   => 'Quantitative Aptitude',
                    'code'    => 'QA',
                    'minutes' => 30,
                ],
                [
                    'title'   => 'Logical Reasoning',
                    'code'    => 'LR',
                    'minutes' => 30,
                ],
                [
                    'title'   => 'Verbal Ability',
                    'code'    => 'VA',
                    'minutes' => 25,
                ],
                [
                    'title'   => 'Technical Fundamentals',
                    'code'    => 'TF',
                    'minutes' => 35,
                ],
            ];

            // Question “banks” per module title (baseline content, but it’s fine to reuse)
            $questionBank = [
                'Quantitative Aptitude' => [
                    [
                        'stem'    => 'If A can complete a task in 10 days and B in 15 days, in how many days can they complete it together?',
                        'options' => [
                            ['10 days', false],
                            ['6 days',  true],
                            ['12 days', false],
                            ['8 days',  false],
                        ],
                    ],
                    [
                        'stem'    => 'What is the compound interest on ₹10,000 at 10% per annum for 2 years (compounded annually)?',
                        'options' => [
                            ['₹2,000', false],
                            ['₹2,100', true],
                            ['₹1,000', false],
                            ['₹1,900', false],
                        ],
                    ],
                    [
                        'stem'    => 'The average of five numbers is 28. If one number is removed, the average becomes 26. What is the removed number?',
                        'options' => [
                            ['36', true],
                            ['30', false],
                            ['32', false],
                            ['40', false],
                        ],
                    ],
                    [
                        'stem'    => 'A train 180 m long passes a man standing on the platform in 9 seconds. What is its speed in km/h?',
                        'options' => [
                            ['60 km/h', false],
                            ['72 km/h', true],
                            ['54 km/h', false],
                            ['80 km/h', false],
                        ],
                    ],
                ],
                'Logical Reasoning' => [
                    [
                        'stem'    => 'Find the next number in the series: 2, 6, 12, 20, ?',
                        'options' => [
                            ['28', true],
                            ['26', false],
                            ['30', false],
                            ['32', false],
                        ],
                    ],
                    [
                        'stem'    => 'If ALL = 25 and BAT = 43, then CAT = ? (Assume A=1, B=2,... Z=26 and sum the letters).',
                        'options' => [
                            ['24', false],
                            ['29', true],
                            ['30', false],
                            ['27', false],
                        ],
                    ],
                    [
                        'stem'    => 'RAIL : LIAR :: GATE : ?',
                        'options' => [
                            ['TEAG', false],
                            ['EATG', false],
                            ['EAGT', true],
                            ['TEGA', false],
                        ],
                    ],
                    [
                        'stem'    => 'In a certain code, TREE is written as UCSF. How is BOOK written in that code?',
                        'options' => [
                            ['CPPM', true],
                            ['CQQM', false],
                            ['AOOL', false],
                            ['BNPL', false],
                        ],
                    ],
                ],
                'Verbal Ability' => [
                    [
                        'stem'    => 'Choose the correct synonym of "Eloquent".',
                        'options' => [
                            ['Fluent', true],
                            ['Silent', false],
                            ['Angry',  false],
                            ['Simple', false],
                        ],
                    ],
                    [
                        'stem'    => 'Fill in the blank: "The teacher asked the students to _____ their homework on time."',
                        'options' => [
                            ['submit', true],
                            ['admit',  false],
                            ['omit',   false],
                            ['permit', false],
                        ],
                    ],
                    [
                        'stem'    => 'Identify the correctly punctuated sentence.',
                        'options' => [
                            ['"Its a nice day", she said.', false],
                            ['"It\'s a nice day," she said.', true],
                            ['"Its a nice day," she said.', false],
                            ['"It\'s a nice day", she said.', false],
                        ],
                    ],
                    [
                        'stem'    => 'Choose the word that is closest in meaning to "meticulous".',
                        'options' => [
                            ['Careful', true],
                            ['Careless', false],
                            ['Lazy',     false],
                            ['Quick',    false],
                        ],
                    ],
                ],
                'Technical Fundamentals' => [
                    [
                        'stem'    => 'What does CPU stand for?',
                        'options' => [
                            ['Central Processing Unit', true],
                            ['Control Power Unit',      false],
                            ['Compute Processing Utility', false],
                            ['Central Parallel Unit',     false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of the following is an input device?',
                        'options' => [
                            ['Monitor',  false],
                            ['Printer',  false],
                            ['Keyboard', true],
                            ['Speaker',  false],
                        ],
                    ],
                    [
                        'stem'    => 'Which model in software engineering follows a linear sequential approach?',
                        'options' => [
                            ['Waterfall Model', true],
                            ['Spiral Model',    false],
                            ['Agile Model',     false],
                            ['RAD Model',       false],
                        ],
                    ],
                    [
                        'stem'    => 'In a relational database, a collection of related records is called:',
                        'options' => [
                            ['Field', false],
                            ['Table', true],
                            ['Cell',  false],
                            ['Index', false],
                        ],
                    ],
                ],
            ];

            foreach ($assessmentDefs as $assIndex => $assInfo) {
                $assessment = Assessment::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'title'     => $assInfo['title'],
                    ],
                    [
                        'type'         => $assInfo['type'],
                        'instructions' => $assInfo['instructions'],
                        'total_marks'  => $assInfo['total_marks'],
                        'is_active'    => $assInfo['is_active'],
                        'open_at'      => $assInfo['open_at'],
                        'close_at'     => $assInfo['close_at'],
                    ]
                );

                // --- Modules for this assessment ---
                foreach ($moduleTemplates as $order => $tpl) {
                    $baseTitle = $tpl['title'];
                    $title     = $assInfo['title'] . ' - ' . $baseTitle;
                    $code      = strtoupper($tpl['code']) . '-' . ($assIndex === 0 ? 'B' : 'F');

                    // module progression:
                    //  - order 1: "unlocked"
                    //  - others : "locked" (your engine enforces this)
                    $status = $order === 0 ? 'unlocked' : 'locked';

                    $startOffset = $assIndex === 0 ? -5 : 20; // baseline earlier, final later
                    $module = Module::firstOrCreate(
                        [
                            'tenant_id'     => $tenant->id,
                            'assessment_id' => $assessment->id,
                            'title'         => $title,
                        ],
                        [
                            'code'                       => $code,
                            'start_at'                   => now()->clone()->addDays($startOffset + $order),
                            'end_at'                     => now()->clone()->addDays($startOffset + $order + 3),
                            'per_student_time_limit_min' => $tpl['minutes'],
                            'order'                      => $order + 1,
                            'status'                     => $status,
                        ]
                    );

                    // --- Questions for this module ---
                    $questionsForModule = $questionBank[$baseTitle] ?? [
                        [
                            'stem'    => 'What is 2 + 2?',
                            'options' => [
                                ['4', true],
                                ['5', false],
                                ['3', false],
                                ['6', false],
                            ],
                        ],
                    ];

                    foreach ($questionsForModule as $qData) {
                        $question = Question::firstOrCreate(
                            [
                                'tenant_id' => $tenant->id,
                                'module_id' => $module->id,
                                'stem'      => $qData['stem'],
                            ],
                            [
                                'type'       => 'MCQ',
                                'difficulty' => $pick(['easy', 'medium', 'hard']),
                                'topic'      => Str::slug($baseTitle),
                                'tags'       => [$baseTitle, 'assessment', $assInfo['title']],
                            ]
                        );

                        foreach ($qData['options'] as [$label, $isCorrect]) {
                            Option::firstOrCreate(
                                [
                                    'question_id' => $question->id,
                                    'label'       => $label,
                                ],
                                [
                                    'is_correct' => $isCorrect,
                                ]
                            );
                        }
                    }
                }
            }

            $this->command?->info('✅ Demo content seeded successfully with Baseline/Final assessments, modules & questions.');
        });
    }
}
