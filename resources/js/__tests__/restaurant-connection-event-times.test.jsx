import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { EventsTable, RejectionsTable } from '@/Pages/Admin/Restaurant/Connections/Show';
import { formatInTz } from '@/Utils/datetime';

const RECEIVED_AT = '2026-09-24T10:02:34+00:00';
const TIMEZONE = 'Asia/Kolkata';

describe('Restaurant connection event timestamps', () => {
    it('renders accepted-event receipt times in the admin timezone instead of raw UTC ISO', () => {
        render(<EventsTable timezone={TIMEZONE} events={[{
            id: 1,
            event_type: 'orderdetails',
            processing_status: 'processed',
            received_at: RECEIVED_AT,
            failure_reason: null,
        }]} />);

        expect(screen.getByText(formatInTz(RECEIVED_AT, TIMEZONE))).toBeInTheDocument();
        expect(screen.queryByText(RECEIVED_AT)).not.toBeInTheDocument();
    });

    it('uses the same timezone formatting for rejected-event receipt times', () => {
        render(<RejectionsTable timezone={TIMEZONE} rejections={[{
            id: 2,
            received_at: RECEIVED_AT,
            failure_reason: 'invalid_token',
            source_ip: '127.0.0.1',
        }]} />);

        expect(screen.getByText(formatInTz(RECEIVED_AT, TIMEZONE))).toBeInTheDocument();
        expect(screen.queryByText(RECEIVED_AT)).not.toBeInTheDocument();
    });
});
