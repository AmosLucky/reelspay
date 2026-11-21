
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reelspay Statistics</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6fa;
            color: #172033;
            padding: 20px;
        }

        .container {
            max-width: 1250px;
            margin: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 22px;
        }

        .header h1 {
            font-size: 26px;
            margin-bottom: 7px;
        }

        .header p {
            color: #8992a5;
            font-size: 13px;
        }

        .refresh {
            background: #2563eb;
            color: white;
            padding: 12px 17px;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: bold;
        }

        .error {
            background: #fef2f2;
            color: #b91c1c;
            padding: 15px;
            border-radius: 9px;
            margin-bottom: 18px;
            overflow-wrap: anywhere;
            font-size: 13px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 6px;
            padding: 5px;
            background: #e8edf5;
            border-radius: 11px;
            margin-bottom: 18px;
        }

        .tab-button {
            flex: 1;
            border: 0;
            background: transparent;
            color: #64748b;
            padding: 14px 10px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .tab-button.active {
            background: white;
            color: #2563eb;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
        }

        /* Statistics cards */
        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }

        .cards.registered-cards {
            grid-template-columns: repeat(3, 1fr);
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #e8ebf2;
            box-shadow: 0 3px 12px rgba(0,0,0,.025);
            min-width: 0;
        }

        .card-label {
            font-size: 11px;
            font-weight: bold;
            color: #8992a5;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .card-number {
            font-size: 31px;
            font-weight: bold;
            margin: 13px 0 7px;
            color: #172033;
            overflow-wrap: anywhere;
        }

        .card-date {
            color: #929bad;
            font-size: 11px;
        }

        .blue { border-top: 4px solid #2563eb; }
        .green { border-top: 4px solid #16a34a; }
        .purple { border-top: 4px solid #9333ea; }
        .orange { border-top: 4px solid #f59e0b; }

        /* List sections */
        .section {
            background: white;
            border-radius: 12px;
            padding: 22px;
            border: 1px solid #e8ebf2;
            box-shadow: 0 3px 12px rgba(0,0,0,.025);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .section-header h2 {
            font-size: 18px;
        }

        .section-header p {
            color: #94a3b8;
            font-size: 12px;
            margin-top: 6px;
        }

        .count {
            background: #eff6ff;
            color: #2563eb;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th {
            text-align: left;
            padding: 13px;
            background: #f8fafc;
            color: #788399;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .5px;
            white-space: nowrap;
        }

        td {
            padding: 14px 13px;
            border-bottom: 1px solid #edf0f5;
            font-size: 12px;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .user-id {
            font-weight: bold;
            color: #334155;
            overflow-wrap: anywhere;
        }

        .country {
            display: inline-block;
            background: #f1f5f9;
            padding: 6px 9px;
            border-radius: 20px;
            font-size: 11px;
        }

        .balance {
            font-weight: bold;
            color: #16a34a;
            white-space: nowrap;
        }

        .date {
            color: #64748b;
            white-space: nowrap;
        }

        .empty {
            text-align: center;
            padding: 45px 15px;
            color: #94a3b8;
        }

        .empty strong {
            display: block;
            font-size: 15px;
            margin-bottom: 8px;
            color: #475569;
        }

        .footer {
            text-align: center;
            margin-top: 22px;
            color: #9ca3af;
            font-size: 11px;
        }

        @media(max-width: 850px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .cards.registered-cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width: 500px) {
            body {
                padding: 12px;
            }

            .header {
                margin-bottom: 17px;
            }

            .header h1 {
                font-size: 21px;
            }

            .refresh {
                width: 100%;
                text-align: center;
            }

            .tabs {
                position: sticky;
                top: 0;
                z-index: 20;
                gap: 4px;
            }

            .tab-button {
                font-size: 12px;
                padding: 13px 5px;
            }

            .cards,
            .cards.registered-cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 9px;
            }

            .card {
                padding: 14px 12px;
                border-radius: 10px;
            }

            .card-label {
                font-size: 9px;
            }

            .card-number {
                font-size: 25px;
                margin: 10px 0 6px;
            }

            .card-date {
                font-size: 10px;
            }

            .section {
                padding: 14px 11px;
            }

            .section-header h2 {
                font-size: 16px;
            }

            .table-wrapper {
                margin: 0 -2px;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <div class="header">
        <div>
            <h1>Reelspay Dashboard</h1>
            <p>App activity and user registration statistics</p>
            <p style="margin-top:6px;">
                {{ $today->format('F d, Y') }}
            </p>
        </div>

        <a href="{{ route('statistics.daily') }}" class="refresh">
            ↻ Refresh Dashboard
        </a>
    </div>

    @if($error)
        <div class="error">
            <strong>Firestore Error:</strong><br>
            {{ $error }}
        </div>
    @endif

    {{-- TABS --}}
    <div class="tabs">
        <button
            type="button"
            class="tab-button active"
            onclick="showTab('daily', this)">
            Daily App Opens
        </button>

        <button
            type="button"
            class="tab-button"
            onclick="showTab('registered', this)">
            Registered Users
        </button>
    </div>

    {{-- DAILY APP OPEN TAB --}}
    <div id="daily-panel" class="tab-panel">

        <div class="cards">
            <div class="card blue">
                <div class="card-label">App Opens Today</div>
                <div class="card-number">
                    {{ number_format($dailyStatistics['today']) }}
                </div>
                <div class="card-date">
                    {{ $today->format('F d, Y') }} · Unique users
                </div>
            </div>

            <div class="card green">
                <div class="card-label">Yesterday</div>
                <div class="card-number">
                    {{ number_format($dailyStatistics['yesterday']) }}
                </div>
                <div class="card-date">
                    {{ $yesterday->format('F d, Y') }} · Unique users
                </div>
            </div>

            <div class="card purple">
                <div class="card-label">Day Before Yesterday</div>
                <div class="card-number">
                    {{ number_format($dailyStatistics['day_before_yesterday']) }}
                </div>
                <div class="card-date">
                    {{ $dayBeforeYesterday->format('F d, Y') }}
                </div>
            </div>

            <div class="card orange">
                <div class="card-label">All-Time App Users</div>
                <div class="card-number">
                    {{ number_format($dailyStatistics['all_time']) }}
                </div>
                <div class="card-date">Unique users in daily records</div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div>
                    <h2>Today's App Activity</h2>
                    <p>Records from reelspay / statistics / daily</p>
                </div>

                <span class="count">
                    {{ number_format(count($todayDocuments)) }} Records
                </span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>User ID</th>
                        <th>Country</th>
                        <th>Balance</th>
                        <th>Activity Date</th>
                    </tr>
                    </thead>

                    <tbody>
                    @forelse($todayDocuments as $index => $document)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td class="user-id">
                                {{ $document['userId'] }}
                            </td>
                            <td>
                                <span class="country">
                                    {{ $document['country'] }}
                                </span>
                            </td>
                            <td class="balance">
                                {{ $document['currency'] }}
                                {{ number_format((float) $document['balance'], 2) }}
                            </td>
                            <td class="date">
                                {{ $document['formattedDate'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty">
                                    <strong>No app activity today</strong>
                                    No daily app-open records were found.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- REGISTERED USERS TAB --}}
    <div id="registered-panel" class="tab-panel" style="display:none;">

        <div class="cards registered-cards">
            <div class="card blue">
                <div class="card-label">Registered Today</div>
                <div class="card-number">
                    {{ number_format($registeredStatistics['today']) }}
                </div>
                <div class="card-date">
                    {{ $today->format('F d, Y') }}
                </div>
            </div>

            <div class="card green">
                <div class="card-label">Registered Yesterday</div>
                <div class="card-number">
                    {{ number_format($registeredStatistics['yesterday']) }}
                </div>
                <div class="card-date">
                    {{ $yesterday->format('F d, Y') }}
                </div>
            </div>

            <div class="card purple">
                <div class="card-label">Total Registered Users</div>
                <div class="card-number">
                    {{ number_format($registeredStatistics['all_time']) }}
                </div>
                <div class="card-date">All profile documents</div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <div>
                    <h2>Users Registered Today</h2>
                    <p>Profiles from reelspay / users / profiles</p>
                </div>

                <span class="count">
                    {{ number_format(count($registeredTodayDocuments)) }} Users
                </span>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>User ID</th>
                        <th>Country</th>
                        <th>Balance</th>
                        <th>Currency</th>
                        <th>Registered At</th>
                    </tr>
                    </thead>

                    <tbody>
                    @forelse($registeredTodayDocuments as $index => $user)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td class="user-id">
                                {{ $user['userId'] }}
                            </td>
                            <td>
                                <span class="country">
                                    {{ $user['country'] }}
                                </span>
                            </td>
                            <td class="balance">
                                {{ number_format((float) $user['balance'], 2) }}
                            </td>
                            <td>{{ $user['currency'] }}</td>
                            <td class="date">
                                {{ $user['formattedDate'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <div class="empty">
                                    <strong>No users registered today</strong>
                                    No profile documents with today's
                                    registeredAt date were found.
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="footer">
        Firestore REST API · Timezone: {{ $timezone }}
    </div>

</div>

<script>
    function showTab(tab, button) {
        document.getElementById('daily-panel').style.display =
            tab === 'daily' ? 'block' : 'none';

        document.getElementById('registered-panel').style.display =
            tab === 'registered' ? 'block' : 'none';

        document.querySelectorAll('.tab-button').forEach(function(item) {
            item.classList.remove('active');
        });

        button.classList.add('active');
    }
</script>

</body>
</html>
