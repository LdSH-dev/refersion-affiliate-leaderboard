import type { LeaderboardEntry } from '../api';
import { formatCommission } from '../format';

export function LeaderboardTable({ entries }: { entries: LeaderboardEntry[] }) {
    return (
        <div className="table-wrap">
            <table className="leaderboard">
                <caption className="visually-hidden">Top affiliates by lifetime commission</caption>
                <thead>
                    <tr>
                        <th scope="col" className="num">
                            Rank
                        </th>
                        <th scope="col">Affiliate</th>
                        <th scope="col" className="num col-id">
                            ID
                        </th>
                        <th scope="col" className="num">
                            Lifetime commission
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {entries.map((entry) => (
                        <tr key={entry.id}>
                            <td className="num">
                                <span className={`rank rank-${entry.rank}`}>{entry.rank}</span>
                            </td>
                            <th scope="row" className="name">
                                {entry.name}
                            </th>
                            <td className="num muted col-id">{entry.id}</td>
                            <td className="num amount">{formatCommission(entry.commission)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
