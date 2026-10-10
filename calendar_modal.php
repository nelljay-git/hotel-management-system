<!-- Booking Calendar Modal -->
<div id="calendarModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
    <div style="background:#fff; width:90%; max-width:1100px; height:85%; margin:4% auto; border-radius:10px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:15px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
            <h2 style="margin:0; font-size:1.4rem;"><i class='bx bx-calendar'></i> Booking Calendar</h2>
            <button onclick="closeCalendarModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding: 10px 20px; display:flex; gap:15px; font-size:0.85rem; border-bottom:1px solid #e2e8f0; flex-wrap:wrap;">
            <span><span style="display:inline-block; width:12px; height:12px; background:#f59e0b; border-radius:50%; margin-right:5px; vertical-align:middle;"></span> Pending</span>
            <span><span style="display:inline-block; width:12px; height:12px; background:#10b981; border-radius:50%; margin-right:5px; vertical-align:middle;"></span> Checked-In</span>
            <span><span style="display:inline-block; width:12px; height:12px; background:#94a3b8; border-radius:50%; margin-right:5px; vertical-align:middle;"></span> Checked-Out</span>
            <span><span style="display:inline-block; width:12px; height:12px; background:#3b82f6; border-radius:50%; margin-right:5px; vertical-align:middle;"></span> Other (Actioned)</span>
            <span style="margin-left:auto; font-weight:bold;"><span style="display:inline-block; width:12px; height:12px; background:#fee2e2; border-radius:50%; margin-right:5px; vertical-align:middle;"></span> Fully Booked</span>
            <span style="font-weight:bold;"><span style="display:inline-block; width:12px; height:12px; background:#fef08a; border-radius:50%; margin-right:5px; vertical-align:middle;"></span> Limited Availability</span>
        </div>
        <div style="flex:1; padding:20px; overflow:hidden;">
            <div id="bookingCalendar" style="height:100%;"></div>
        </div>
    </div>
</div>

<!-- Event Details Popup Modal -->
<div id="eventDetailsModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000;">
    <div style="background:#fff; width:450px; max-width:90%; margin:10% auto; border-radius:8px; overflow:hidden; box-shadow:0 5px 20px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; padding:15px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc;">
            <h3 style="margin:0; font-size:1.2rem;">Booking Details</h3>
            <button onclick="closeEventModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div style="padding:20px;" id="eventDetailsContent">
            <!-- Content injected by JS -->
        </div>
    </div>
</div>

<!-- FullCalendar Library (local, offline-compatible) -->
<script src='js/vendor/fullcalendar.min.js'></script>
<script>
    let calendar;
    
    function openCalendarModal() {
        document.getElementById('calendarModal').style.display = 'block';
        if (!calendar) {
            let calendarEl = document.getElementById('bookingCalendar');
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: 'calendar_events_ajax.php',
                eventClick: function(info) {
                    if (info.event.display === 'background' || info.event.extendedProps.is_availability) {
                        return; // Ignore clicks on availability background events
                    }
                    showEventDetails(info.event);
                },
                dateClick: function(info) {
                    // Ask user to create a booking on this date
                    const friendlyDate = info.date.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' });
                    if (confirm("Do you want to create a booking on " + friendlyDate + "?")) {
                        closeCalendarModal();

                        if (window.location.pathname.includes('booking.php')) {
                            // Fetch the smart next-available check-in time from the server
                            fetch('next_checkin_ajax.php?date=' + info.dateStr)
                                .then(r => r.json())
                                .then(data => {
                                    const checkInInput = document.getElementById('check_in_time');
                                    if (!checkInInput) return;

                                    if (data.error) {
                                        // Fallback to 10:00 AM
                                        checkInInput.value = info.dateStr + 'T10:00';
                                    } else {
                                        checkInInput.value = data.suggested_checkin;

                                        // Show a subtle toast/banner if we bumped the time
                                        if (data.has_prior_booking) {
                                            const msg = `⏰ Previous booking checks out at ${data.latest_checkout.replace('T',' ')}. Check-in set to ${data.suggested_checkin.replace('T',' ')} (${data.buffer_min}-min buffer).`;
                                            showCalendarNotice(msg, 'info');
                                        }
                                    }

                                    checkInInput.dispatchEvent(new Event('change'));
                                    document.getElementById('duration_type').focus();
                                })
                                .catch(() => {
                                    // Network error fallback
                                    const checkInInput = document.getElementById('check_in_time');
                                    if (checkInInput) {
                                        checkInInput.value = info.dateStr + 'T10:00';
                                        checkInInput.dispatchEvent(new Event('change'));
                                        document.getElementById('duration_type').focus();
                                    }
                                });
                        } else {
                            // Navigate to booking.php with date param; smart time applied on load
                            window.location.href = 'booking.php?date=' + info.dateStr;
                        }
                    }
                },
                height: '100%',
                displayEventTime: true
            });
            calendar.render();
        } else {
            calendar.refetchEvents();
        }
    }
    
    function closeCalendarModal() {
        document.getElementById('calendarModal').style.display = 'none';
    }

    function showEventDetails(event) {
        let p = event.extendedProps;
        // Booking status in plain words (matches the Dashboard language)
        const statusLabel = p.status === 'Actioned' ? 'Confirmed' : p.status;
        const statusSub = p.status === 'Actioned' ? ' (' + p.checkin_status + ')' : '';
        // Room state in plain words — every room status gets its own label
        // (previously anything but Available was mislabeled as "Checked Out").
        const roomStateMap = {
            'Available':   { text: 'Available — ready for guests',      bg: '#10b981' },
            'Occupied':    { text: 'Occupied — guest is inside',        bg: '#3b82f6' },
            'Cleaning':    { text: 'Cleaning — housekeeping required',  bg: '#f59e0b' },
            'Maintenance': { text: 'Under maintenance',                 bg: '#ef4444' }
        };
        const rs = roomStateMap[p.room_status] || { text: p.room_status, bg: '#64748b' };
        // Flag contradictory data instead of showing two clashing badges
        let mismatch = '';
        if (p.checkin_status === 'Checked-in' && p.room_status === 'Available') {
            mismatch = `<div style="margin-bottom:15px; padding:10px 14px; border-radius:8px; background:#fffbeb; border:1px solid #fde68a; font-size:0.82rem; color:#92400e; font-weight:500;">
                Heads up: the guest is checked in but the room shows Available. Verify the room status on the Rooms page.</div>`;
        } else if (p.checkin_status === 'Checked-out' && p.room_status === 'Occupied') {
            mismatch = `<div style="margin-bottom:15px; padding:10px 14px; border-radius:8px; background:#fffbeb; border:1px solid #fde68a; font-size:0.82rem; color:#92400e; font-weight:500;">
                Heads up: the guest checked out but the room still shows Occupied. Send it for cleaning on the Rooms page.</div>`;
        }
        let content = `
            <div style="margin-bottom:15px;">
                <div style="font-size:0.85rem; color:#64748b; margin-bottom:3px;">Guest Name</div>
                <div style="font-weight:600; font-size:1.1rem;">${p.guest_name}</div>
            </div>
            <div style="display:flex; gap:20px; margin-bottom:15px;">
                <div>
                    <div style="font-size:0.85rem; color:#64748b; margin-bottom:3px;">Room</div>
                    <div style="font-weight:600;">${p.room_number} <small>(${p.room_type})</small></div>
                </div>
                <div>
                    <div style="font-size:0.85rem; color:#64748b; margin-bottom:3px;">Guests</div>
                    <div style="font-weight:600;">${p.num_guests}</div>
                </div>
            </div>
            <div style="display:flex; gap:20px; margin-bottom:15px;">
                <div>
                    <div style="font-size:0.85rem; color:#64748b; margin-bottom:3px;">Check In</div>
                    <div style="font-weight:600; font-size:0.95rem;">${p.check_in_time}</div>
                </div>
                <div>
                    <div style="font-size:0.85rem; color:#64748b; margin-bottom:3px;">Check Out</div>
                    <div style="font-weight:600; font-size:0.95rem;">${p.check_out_time}</div>
                </div>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:0.85rem; color:#64748b; margin-bottom:3px;">Booking Status</div>
                <span class="badge" style="background-color:${event.backgroundColor}; padding:5px 10px; font-size:0.9rem;">${statusLabel}${statusSub}</span>
            </div>
            <div style="margin-bottom:20px;">
                <div style="font-size:0.85rem; color:#64748b; margin-bottom:3px;">Room State</div>
                <span class="badge" style="background:${rs.bg}; padding:5px 10px; font-size:0.9rem;">
                    ${rs.text}
                </span>
            </div>
            ${mismatch}
            <hr style="border:0; border-top:1px solid #e2e8f0; margin-bottom:15px;">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                ${p.status === 'Pending' ? `<a href="checkin.php?booking_id=${p.booking_id}" class="btn btn-primary btn-sm"><i class='bx bx-check'></i> Check In</a>` : ''}
                ${p.checkin_status === 'Checked-in' ? `
                    <form method="POST" action="checkin.php" style="margin:0;">
                        <input type="hidden" name="checkout_checkin_id" value="${p.checkin_id}">
                        <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Proceed to Check-Out?')"><i class='bx bx-log-out'></i> Check Out</button>
                    </form>
                ` : ''}
                <a href="checkin.php?booking_id=${p.booking_id}" class="btn btn-secondary btn-sm"><i class='bx bx-edit'></i> Manage</a>
            </div>
        `;
        document.getElementById('eventDetailsContent').innerHTML = content;
        document.getElementById('eventDetailsModal').style.display = 'block';
    }

    function closeEventModal() {
        document.getElementById('eventDetailsModal').style.display = 'none';
    }

    /**
     * Show a brief info/warning notice banner on the booking form.
     * Automatically dismisses after 6 seconds.
     */
    function showCalendarNotice(message, type = 'info') {
        let banner = document.getElementById('calendar_notice_banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'calendar_notice_banner';
            banner.style.cssText = [
                'position:fixed', 'top:70px', 'left:50%', 'transform:translateX(-50%)',
                'z-index:99999', 'max-width:600px', 'width:90%', 'padding:12px 20px',
                'border-radius:8px', 'font-size:0.9rem', 'font-weight:500',
                'box-shadow:0 4px 15px rgba(0,0,0,0.15)', 'transition:opacity 0.4s',
                'display:flex', 'align-items:center', 'gap:10px'
            ].join(';');
            document.body.appendChild(banner);
        }
        const colors = {
            info    : { bg: '#eff6ff', color: '#1d4ed8', border: '#bfdbfe' },
            warning : { bg: '#fffbeb', color: '#92400e', border: '#fde68a' },
            success : { bg: '#f0fdf4', color: '#166534', border: '#bbf7d0' },
        };
        const c = colors[type] || colors.info;
        banner.style.background   = c.bg;
        banner.style.color        = c.color;
        banner.style.border       = `1px solid ${c.border}`;
        banner.style.opacity      = '1';
        banner.innerHTML = `<i class='bx bx-info-circle' style='font-size:1.2rem;'></i> ${message}`;

        clearTimeout(banner._hideTimeout);
        banner._hideTimeout = setTimeout(() => {
            banner.style.opacity = '0';
            setTimeout(() => { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 400);
        }, 6000);
    }
</script>
