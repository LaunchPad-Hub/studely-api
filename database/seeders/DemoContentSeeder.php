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

            // --- Tenant ---
            $tenant = Tenant::firstOrCreate(
                ['code' => 'demo'],
                ['name' => 'TechNirma Institute of Technology']
            );

            // --- Students ---
            $studentNames = [
                'Aarav Sharma', 'Diya Patel', 'Ishaan Mehta', 'Priya Nair', 'Rohan Iyer',
                'Ananya Reddy', 'Karan Gupta', 'Sneha Raj', 'Devansh Das', 'Meera Singh',
                'Kabir Bansal', 'Ritika Chopra', 'Aditi Pillai', 'Rahul Menon', 'Simran Deshmukh',
            ];

            /* -----------------------------------------------------------------
             |  ASSESSMENTS + MODULES + QUESTIONS
             |  Baseline + Final, each with ordered modules and questions.
             |  Modules have status: "unlocked" / "locked" for progression.
             * ---------------------------------------------------------------- */

            $assessmentDefs = [
                [
                    'title'       => 'Baseline Assessment',
                    'order'       => 1,
                    'type'        => 'online',
                    'instructions'=> 'This baseline assessment measures the student’s current level across multiple categories before any training. Complete all modules in order.',
                    'total_marks' => 100,
                    'is_active'   => true,
                    'open_at'     => now()->clone()->subDays(7),
                    'close_at'    => now()->clone()->addDays(7),
                ],
                [
                    'title'       => 'Final Assessment',
                    'order'       => 2,
                    'type'        => 'online',
                    'instructions'=> 'This final assessment compares performance after the training program. Complete all modules in order.',
                    'total_marks' => 100,
                    'is_active'   => true,
                    'open_at'     => now()->clone()->addDays(15),
                    'close_at'    => now()->clone()->addDays(30),
                ],
            ];

            // 8 modules → full journey from aptitude to soft skills
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
                [
                    'title'   => 'Data Interpretation',
                    'code'    => 'DI',
                    'minutes' => 30,
                ],
                [
                    'title'   => 'Programming Logic',
                    'code'    => 'PL',
                    'minutes' => 35,
                ],
                [
                    'title'   => 'Operating Systems & Networking',
                    'code'    => 'OSN',
                    'minutes' => 35,
                ],
                [
                    'title'   => 'Soft Skills & Professional Communication',
                    'code'    => 'SSP',
                    'minutes' => 20,
                ],
            ];

            // Question “banks” per module title – 9 MCQs each
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
                    [
                        'stem'    => 'A shopkeeper offers a discount of 20% on a marked price of ₹2,500. What is the selling price?',
                        'options' => [
                            ['₹2,000', true],
                            ['₹2,100', false],
                            ['₹1,900', false],
                            ['₹2,200', false],
                        ],
                    ],
                    [
                        'stem'    => 'If the ratio of boys to girls in a class is 3:2 and there are 30 students in total, how many girls are there?',
                        'options' => [
                            ['10', false],
                            ['12', false],
                            ['15', true],
                            ['18', false],
                        ],
                    ],
                    [
                        'stem'    => 'A sum of money doubles itself in 6 years at simple interest. What is the rate of interest per annum?',
                        'options' => [
                            ['10%', false],
                            ['12%', false],
                            ['16.67%', true],
                            ['6%', false],
                        ],
                    ],
                    [
                        'stem'    => 'The perimeter of a rectangle is 60 cm and its length is 18 cm. What is its breadth?',
                        'options' => [
                            ['12 cm', true],
                            ['14 cm', false],
                            ['10 cm', false],
                            ['9 cm',  false],
                        ],
                    ],
                    [
                        'stem'    => 'If the marked price of an item is ₹1,200 and it is sold at a discount of 15%, what is the selling price?',
                        'options' => [
                            ['₹1,020', false],
                            ['₹1,050', false],
                            ['₹1,080', true],
                            ['₹1,100', false],
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
                    [
                        'stem'    => 'If A is the brother of B, B is the sister of C, and C is the father of D, then A is D’s:',
                        'options' => [
                            ['Father', false],
                            ['Uncle/Aunt', true],
                            ['Brother', false],
                            ['Cousin', false],
                        ],
                    ],
                    [
                        'stem'    => 'Four friends P, Q, R, S are sitting in a row. P is to the left of Q, R is to the right of Q, and S is to the right of R. Who is sitting at the extreme right?',
                        'options' => [
                            ['P', false],
                            ['Q', false],
                            ['R', false],
                            ['S', true],
                        ],
                    ],
                    [
                        'stem'    => 'If BLUE is coded as 2135 and PINK as 7489, then LINK is coded as:',
                        'options' => [
                            ['4189', true],
                            ['7189', false],
                            ['7489', false],
                            ['2135', false],
                        ],
                    ],
                    [
                        'stem'    => 'Identify the odd one out: Apple, Banana, Mango, Carrot.',
                        'options' => [
                            ['Apple',  false],
                            ['Banana', false],
                            ['Mango',  false],
                            ['Carrot', true],
                        ],
                    ],
                    [
                        'stem'    => 'If TODAY is coded as UPEBZ, how is EXAM coded?',
                        'options' => [
                            ['FYBN', true],
                            ['EZBN', false],
                            ['EYAM', false],
                            ['DXBN', false],
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
                    [
                        'stem'    => 'Choose the correct antonym of "Scarce".',
                        'options' => [
                            ['Rare', false],
                            ['Plentiful', true],
                            ['Small', false],
                            ['Short', false],
                        ],
                    ],
                    [
                        'stem'    => 'Fill in the blank: "The project was completed _____ the deadline."',
                        'options' => [
                            ['on', true],
                            ['in', false],
                            ['for', false],
                            ['by', false],
                        ],
                    ],
                    [
                        'stem'    => 'Select the sentence with correct subject–verb agreement.',
                        'options' => [
                            ['The list of items are on the table.', false],
                            ['The list of items is on the table.', true],
                            ['The lists of item is on the table.', false],
                            ['The lists of item are on the table.', false],
                        ],
                    ],
                    [
                        'stem'    => 'Choose the correct spelling.',
                        'options' => [
                            ['Maintenence', false],
                            ['Maintenance', true],
                            ['Maintanence', false],
                            ['Maintainance', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of the following is a correctly formed question?',
                        'options' => [
                            ['You are coming today?', false],
                            ['Are you coming today?', true],
                            ['Coming you are today?', false],
                            ['Today you are coming?', false],
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
                    [
                        'stem'    => 'Which of the following is a non-volatile memory?',
                        'options' => [
                            ['RAM', false],
                            ['ROM', true],
                            ['Cache', false],
                            ['Register', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which protocol is used to browse the World Wide Web?',
                        'options' => [
                            ['FTP', false],
                            ['HTTP/HTTPS', true],
                            ['SMTP', false],
                            ['POP3', false],
                        ],
                    ],
                    [
                        'stem'    => 'In programming, what does "OOP" stand for?',
                        'options' => [
                            ['Object Oriented Programming', true],
                            ['Operational Output Process',  false],
                            ['Open Object Protocol',        false],
                            ['Ordered Oriented Process',    false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of these is an example of an operating system?',
                        'options' => [
                            ['MySQL', false],
                            ['Linux', true],
                            ['HTML',  false],
                            ['Oracle', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which device connects multiple computers within a local area network?',
                        'options' => [
                            ['Router', false],
                            ['Switch', true],
                            ['Modem',  false],
                            ['Firewall', false],
                        ],
                    ],
                ],

                'Data Interpretation' => [
                    [
                        'stem'    => 'In a bar chart, which axis usually represents categories?',
                        'options' => [
                            ['X-axis', true],
                            ['Y-axis', false],
                            ['Both',   false],
                            ['None',   false],
                        ],
                    ],
                    [
                        'stem'    => 'A company’s sales increased from ₹2,00,000 to ₹2,40,000. What is the percentage increase?',
                        'options' => [
                            ['15%', false],
                            ['20%', true],
                            ['18%', false],
                            ['25%', false],
                        ],
                    ],
                    [
                        'stem'    => 'If 30% of a class prefers tea, 50% prefers coffee and the rest prefer juice, what percentage prefer juice?',
                        'options' => [
                            ['10%', false],
                            ['15%', false],
                            ['20%', true],
                            ['25%', false],
                        ],
                    ],
                    [
                        'stem'    => 'A pie chart shows that 90° of the chart is allocated to "Travel". What fraction of the total is this?',
                        'options' => [
                            ['1/2', false],
                            ['1/4', true],
                            ['1/3', false],
                            ['3/4', false],
                        ],
                    ],
                    [
                        'stem'    => 'In a data table, which measure is most affected by extreme values?',
                        'options' => [
                            ['Median', false],
                            ['Mode',   false],
                            ['Mean',   true],
                            ['Range',  false],
                        ],
                    ],
                    [
                        'stem'    => 'A line graph shows a steady increase every year. Which of the following is true?',
                        'options' => [
                            ['There is no change over time.', false],
                            ['Values decrease every year.',   false],
                            ['Values fluctuate randomly.',    false],
                            ['Values grow consistently.',     true],
                        ],
                    ],
                    [
                        'stem'    => 'Which tool is most appropriate to quickly compare proportions among categories?',
                        'options' => [
                            ['Line graph', false],
                            ['Pie chart',  true],
                            ['Scatter plot', false],
                            ['Histogram',  false],
                        ],
                    ],
                    [
                        'stem'    => 'If the frequency of a class in a histogram is high, what does it indicate?',
                        'options' => [
                            ['Few observations', false],
                            ['Many observations', true],
                            ['No observations',   false],
                            ['Outliers only',     false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of these is NOT a measure of central tendency?',
                        'options' => [
                            ['Mean', false],
                            ['Median', false],
                            ['Mode', false],
                            ['Variance', true],
                        ],
                    ],
                ],

                'Programming Logic' => [
                    [
                        'stem'    => 'Which of the following is a loop structure in most programming languages?',
                        'options' => [
                            ['if', false],
                            ['for', true],
                            ['switch', false],
                            ['case', false],
                        ],
                    ],
                    [
                        'stem'    => 'An algorithm that calls itself is known as:',
                        'options' => [
                            ['Iterative', false],
                            ['Recursive', true],
                            ['Sequential', false],
                            ['Parallel', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which data structure works on the principle of FIFO?',
                        'options' => [
                            ['Stack', false],
                            ['Queue', true],
                            ['Array', false],
                            ['Tree',  false],
                        ],
                    ],
                    [
                        'stem'    => 'What will be the output of: if (5 > 3 && 2 < 4) ?',
                        'options' => [
                            ['false', false],
                            ['true',  true],
                            ['0',     false],
                            ['Error', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of the following is a valid boolean expression?',
                        'options' => [
                            ['x + y',      false],
                            ['x > y',      true],
                            ['x * y',      false],
                            ['x / y',      false],
                        ],
                    ],
                    [
                        'stem'    => 'A function that does not return any value is often declared with which return type?',
                        'options' => [
                            ['int', false],
                            ['void', true],
                            ['bool', false],
                            ['char', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of the following is best for searching in a sorted array?',
                        'options' => [
                            ['Linear search', false],
                            ['Binary search', true],
                            ['Hashing',       false],
                            ['DFS',           false],
                        ],
                    ],
                    [
                        'stem'    => 'Which symbol is commonly used to represent "OR" in many programming languages?',
                        'options' => [
                            ['&&', false],
                            ['||', true],
                            ['!',  false],
                            ['==', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which structure is best suited to represent hierarchical relationships?',
                        'options' => [
                            ['Array', false],
                            ['Tree',  true],
                            ['Queue', false],
                            ['Hash table', false],
                        ],
                    ],
                ],

                'Operating Systems & Networking' => [
                    [
                        'stem'    => 'Which of the following is a function of an operating system?',
                        'options' => [
                            ['Text editing',      false],
                            ['Process management', true],
                            ['Spreadsheet design', false],
                            ['Presentation design', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of these is a process state?',
                        'options' => [
                            ['Running', true],
                            ['Connecting', false],
                            ['Transmitting', false],
                            ['Browsing', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which protocol is used for secure communication over the internet?',
                        'options' => [
                            ['HTTP', false],
                            ['HTTPS', true],
                            ['FTP', false],
                            ['SMTP', false],
                        ],
                    ],
                    [
                        'stem'    => 'What is the smallest addressable unit of memory?',
                        'options' => [
                            ['Bit',  false],
                            ['Byte', true],
                            ['Nibble', false],
                            ['Word', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of the following uniquely identifies a device on a network?',
                        'options' => [
                            ['MAC address', true],
                            ['Subnet mask', false],
                            ['Gateway',     false],
                            ['DNS',         false],
                        ],
                    ],
                    [
                        'stem'    => 'Which scheduling algorithm executes the process that arrives first?',
                        'options' => [
                            ['Round Robin',     false],
                            ['First-Come, First-Served', true],
                            ['Shortest Job First', false],
                            ['Priority Scheduling', false],
                        ],
                    ],
                    [
                        'stem'    => 'What does DNS stand for?',
                        'options' => [
                            ['Dynamic Network Service', false],
                            ['Domain Name System',      true],
                            ['Data Name Service',       false],
                            ['Domain Network Service',  false],
                        ],
                    ],
                    [
                        'stem'    => 'Which device is used to connect two different networks?',
                        'options' => [
                            ['Switch',  false],
                            ['Router',  true],
                            ['Hub',     false],
                            ['Repeater', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which one is an example of a multitasking operating system?',
                        'options' => [
                            ['MS-DOS', false],
                            ['Windows 10', true],
                            ['CP/M', false],
                            ['BASIC', false],
                        ],
                    ],
                ],

                'Soft Skills & Professional Communication' => [
                    [
                        'stem'    => 'Which of the following is an example of active listening?',
                        'options' => [
                            ['Interrupting to share your opinion', false],
                            ['Maintaining eye contact and summarising', true],
                            ['Checking your phone while listening', false],
                            ['Looking away while someone speaks', false],
                        ],
                    ],
                    [
                        'stem'    => 'In a professional email, which is the most appropriate greeting?',
                        'options' => [
                            ['Hey bro,', false],
                            ['Dear Sir/Madam,', true],
                            ['Yo,', false],
                            ['Hi dude,', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which body language generally shows confidence during an interview?',
                        'options' => [
                            ['Avoiding eye contact', false],
                            ['Slouching',           false],
                            ['Sitting upright',     true],
                            ['Constantly checking the time', false],
                        ],
                    ],
                    [
                        'stem'    => 'What is the primary purpose of a CV/Resume?',
                        'options' => [
                            ['To share personal stories', false],
                            ['To present skills and experience', true],
                            ['To list only hobbies', false],
                            ['To criticise previous employers', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of the following is an example of constructive feedback?',
                        'options' => [
                            ['"You are always wrong."', false],
                            ['"You did this badly."', false],
                            ['"If you structure your answer like this, it will be clearer."', true],
                            ['"This is useless."', false],
                        ],
                    ],
                    [
                        'stem'    => 'What is the best way to close a professional email?',
                        'options' => [
                            ['Thanks & Regards,', true],
                            ['Bye bye,', false],
                            ['See ya,', false],
                            ['Later,', false],
                        ],
                    ],
                    [
                        'stem'    => 'During a group discussion, a good practice is to:',
                        'options' => [
                            ['Speak non-stop without pause', false],
                            ['Listen to others and build on points', true],
                            ['Ignore quieter members', false],
                            ['Change the topic frequently', false],
                        ],
                    ],
                    [
                        'stem'    => 'Which of the following is most appropriate for a LinkedIn profile photo?',
                        'options' => [
                            ['A clear, professional headshot', true],
                            ['A party picture', false],
                            ['A meme image', false],
                            ['A random landscape', false],
                        ],
                    ],
                    [
                        'stem'    => 'What should you ideally do before a job interview?',
                        'options' => [
                            ['Arrive exactly at the interview time', false],
                            ['Research the company and role',        true],
                            ['Skip reading the job description',     false],
                            ['Avoid preparing questions',            false],
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
