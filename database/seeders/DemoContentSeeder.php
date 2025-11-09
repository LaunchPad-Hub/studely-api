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

            // Some static data to pick from
            $branches = ['Computer Science', 'Electronics', 'Business', 'Communication'];
            $cities   = ['Pune', 'Bangalore', 'Hyderabad', 'Mumbai', 'Delhi', 'Ahmedabad', 'Jaipur', 'Kolkata', 'Chennai'];

            // --- Tenant ---
            $tenant = Tenant::firstOrCreate(
                ['code' => 'demo'],
                ['name' => 'TechNirma Institute of Technology']
            );

            // --- Colleges ---
            $colleges = collect([
                ['name' => 'TechNirma College of Engineering', 'code' => 'ENG', 'location' => 'Pune',      'description' => 'Focuses on engineering and applied sciences.'],
                ['name' => 'Arya College of Management Studies', 'code' => 'MGT', 'location' => 'Bangalore', 'description' => 'Renowned for its business and management programs.'],
                ['name' => 'Nirma Institute of Computer Science', 'code' => 'CSE', 'location' => 'Hyderabad', 'description' => 'Specializes in software engineering and computing.'],
                ['name' => 'Vidya College of Communication',      'code' => 'COM', 'location' => 'Mumbai',    'description' => 'Dedicated to soft skills and public communication.'],
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
                    'password'          => Hash::make('Password!234'),
                    'email_verified_at' => now(),
                    'registered_at'     => now(),
                ]
            );
            if (method_exists($admin, 'assignRole')) {
                $admin->assignRole('CollegeAdmin');
            }

            // --- Create realistic Indian student names ---
            $studentNames = [
                'Aarav Sharma', 'Diya Patel', 'Ishaan Mehta', 'Priya Nair', 'Rohan Iyer',
                'Ananya Reddy', 'Karan Gupta', 'Sneha Raj', 'Devansh Das', 'Meera Singh',
                'Kabir Bansal', 'Ritika Chopra', 'Aditi Pillai', 'Rahul Menon', 'Simran Deshmukh',
            ];

            foreach ($studentNames as $i => $name) {
                $email = strtolower(Str::slug($name, '.')) . '@technirma.in';

                // --- Create User ---
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

                // --- Pick random college ---
                $college = $colleges->random();

                // --- DOB: ~19–22 years ago with some month/day variance
                $dob = now()
                    ->clone()
                    ->subYears($num(19, 22))
                    ->subMonths($num(1, 11))
                    ->subDays($num(0, 27));

                // --- Create linked Student ---
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
                            'phone'   => '+91' . $digits(10), // simple 10-digit Indian mobile
                            'address' => $pick($cities),
                        ],
                    ]
                );
            }

            // --- Assessments ---
            $assessments = [
                ['title' => 'Baseline Assessment', 'type' => 'MCQ'],
                ['title' => 'Final Assessment',    'type' => 'MCQ'],
            ];

            foreach ($assessments as $assInfo) {
                $assessment = Assessment::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'title'     => $assInfo['title'],
                    ],
                    [
                        'type'         => $assInfo['type'],
                        'instructions' => 'Please attempt all questions carefully.',
                        'total_marks'  => 100,
                        'is_active'    => true,
                    ]
                );

                // --- Modules ---
                $moduleTitles = [
                    'Quantitative Aptitude',
                    'Logical Reasoning',
                    'Verbal Ability',
                    'Technical Knowledge',
                    'Computer Fundamentals',
                    'Software Engineering Basics',
                    'Communication Skills',
                    'Personality Development',
                ];

                // 7 or 8 modules
                $count = $num(7, 8);
                foreach (array_values(array_slice($moduleTitles, 0, $count)) as $order => $title) {
                    $module = Module::firstOrCreate(
                        [
                            'tenant_id'     => $tenant->id,
                            'assessment_id' => $assessment->id,
                            'title'         => $title,
                        ],
                        [
                            'code'                        => 'MOD-' . Str::upper(Str::slug($title, '-')),
                            'start_at'                    => now()->clone()->subDays($num(5, 20)),
                            'end_at'                      => now()->clone()->addDays($num(5, 20)),
                            'per_student_time_limit_min'  => 45,
                            'order'                       => $order + 1,
                        ]
                    );

                    // --- Add few questions per module ---
                    foreach (range(1, 5) as $qNo) {
                        $stem = match ($title) {
                            'Quantitative Aptitude'      => "If A can complete a task in 10 days and B in 15 days, how long will they take together?",
                            'Logical Reasoning'          => "Find the next number in the series: 2, 6, 12, 20, ?",
                            'Verbal Ability'             => "Choose the correct synonym of 'Eloquent'.",
                            'Technical Knowledge'        => "What does CPU stand for?",
                            'Computer Fundamentals'      => "Which of the following is an input device?",
                            'Software Engineering Basics'=> "Which model follows a linear sequential approach?",
                            'Communication Skills'       => "Which is most important for effective communication?",
                            default                      => "What is 2 + 2?",
                        };

                        $question = Question::firstOrCreate(
                            [
                                'tenant_id' => $tenant->id,
                                'module_id' => $module->id,
                                'stem'      => $stem,
                            ],
                            [
                                'type'       => 'MCQ',
                                'difficulty' => $pick(['easy', 'medium', 'hard']),
                                'topic'      => Str::slug($title),
                                'tags'       => [$title, 'assessment', 'practice'],
                            ]
                        );

                        // --- Options ---
                        $options = match ($title) {
                            'Quantitative Aptitude' => [
                                ['10 days', false],
                                ['6 days',  true],
                                ['12 days', false],
                                ['8 days',  false],
                            ],
                            'Logical Reasoning' => [
                                ['30', false],
                                ['28', true],
                                ['26', false],
                                ['24', false],
                            ],
                            'Verbal Ability' => [
                                ['Fluent', true],
                                ['Silent', false],
                                ['Angry',  false],
                                ['Simple', false],
                            ],
                            'Technical Knowledge' => [
                                ['Central Processing Unit', true],
                                ['Control Power Unit',      false],
                                ['Compute Processing Utility', false],
                                ['Central Parallel Unit',     false],
                            ],
                            'Computer Fundamentals' => [
                                ['Monitor',  false],
                                ['Printer',  false],
                                ['Keyboard', true],
                                ['Speaker',  false],
                            ],
                            'Software Engineering Basics' => [
                                ['Waterfall Model', true],
                                ['Spiral Model',    false],
                                ['Agile Model',     false],
                                ['RAD Model',       false],
                            ],
                            'Communication Skills' => [
                                ['Listening',       true],
                                ['Speaking fast',   false],
                                ['Using jargon',    false],
                                ['Interrupting others', false],
                            ],
                            default => [
                                ['4', true],
                                ['5', false],
                                ['6', false],
                                ['3', false],
                            ],
                        };

                        foreach ($options as [$label, $isCorrect]) {
                            Option::firstOrCreate(
                                [
                                    'question_id' => $question->id,
                                    'label'       => $label,
                                ],
                                ['is_correct' => $isCorrect]
                            );
                        }
                    }
                }
            }

            $this->command->info('✅ Demo content seeded successfully without Faker (cPanel-safe).');
        });
    }
}
