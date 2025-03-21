jQuery(document).ready(function($) {
    // Variables
    let currentTeamId = '';
    let teamProgressChart = null;
    let courseCompletionChart = null;
    
    // Get the team ID from the dropdown or use the first one
    if ($('#team-select').length) {
        currentTeamId = $('#team-select').val();
        
        // Listen for team selection changes
        $('#team-select').on('change', function() {
            currentTeamId = $(this).val();
            loadTeamProgressData();
        });
    } else {
        // If no dropdown (in case of shortcode with team_id), find the team ID from the data attribute
        currentTeamId = $('.team-progress-dashboard').data('team-id');
    }
    
    // Load the data for the selected team
    loadTeamProgressData();
    
    // Tab navigation
    $('.tab-navigation li').on('click', function() {
        const tabId = $(this).data('tab');
        
        // Update active tab
        $('.tab-navigation li').removeClass('active');
        $(this).addClass('active');
        
        // Show the selected tab content
        $('.tab-pane').removeClass('active');
        $('#' + tabId).addClass('active');
    });
    
    // Search functionality for members
    $('#member-search').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        
        $('#member-progress-tbody tr').each(function() {
            const memberName = $(this).find('td:first').text().toLowerCase();
            if (memberName.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    // Search functionality for courses
    $('#course-search').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        
        $('#course-progress-tbody tr').each(function() {
            const courseName = $(this).find('td:first').text().toLowerCase();
            if (courseName.includes(searchTerm)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });
    
    // Modal functionality
    $('.close').on('click', function() {
        $('#course-details-modal').css('display', 'none');
    });
    
    $(window).on('click', function(event) {
        if ($(event.target).is('#course-details-modal')) {
            $('#course-details-modal').css('display', 'none');
        }
    });
    
    // Function to load team progress data via AJAX
    function loadTeamProgressData() {
        if (!currentTeamId) return;
        
        // Show loading indicators
        $('#total-courses-value, #avg-completion-value, #team-members-value').text('-');
        $('#member-progress-tbody').html('<tr><td colspan="5">Loading member data...</td></tr>');
        $('#course-progress-tbody').html('<tr><td colspan="4">Loading course data...</td></tr>');
        
        // Get parameters from data attributes if available
        const members_count = window.teamProgressParams ? window.teamProgressParams.members_count : 10;
        const courses_count = window.teamProgressParams ? window.teamProgressParams.courses_count : 10;
        
        // Make AJAX request
        $.ajax({
            url: team_progress_data.ajax_url,
            type: 'POST',
            data: {
                action: 'get_team_progress_data',
                nonce: team_progress_data.nonce,
                team_id: currentTeamId,
                members_count: members_count,
                courses_count: courses_count
            },
            success: function(response) {
                if (response.success) {
                    updateDashboard(response.data);
                } else {
                    alert(response.data.message || 'Error loading team progress data.');
                }
            },
            error: function() {
                alert('Network error when trying to load team progress data.');
            }
        });
    }
    
    // Function to update the dashboard with the loaded data
    function updateDashboard(data) {
        // Update overview stats
        $('#total-courses-value').text(data.team.total_courses);
        $('#avg-completion-value').text(data.team.avg_completion + '%');
        $('#team-members-value').text(data.team.member_count);
        
        // Update overview charts
        updateOverviewCharts(data);
        
        // Update member progress table
        updateMemberProgressTable(data.members);
        
        // Update course progress table
        updateCourseProgressTable(data.courses);
    }
    
    // Function to update the overview charts
    function updateOverviewCharts(data) {
        // Team progress chart (doughnut chart)
        const teamProgressCtx = document.getElementById('team-progress-chart');
        if (teamProgressCtx) {
            if (teamProgressChart) {
                teamProgressChart.destroy();
            }
            
            teamProgressChart = new Chart(teamProgressCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'In Progress'],
                    datasets: [{
                        data: [data.team.avg_completion, 100 - data.team.avg_completion],
                        backgroundColor: ['#28a745', '#e9ecef'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.raw + '%';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Course completion chart (bar chart)
        const courseCompletionCtx = document.getElementById('course-completion-chart');
        if (courseCompletionCtx && data.courses.length > 0) {
            if (courseCompletionChart) {
                courseCompletionChart.destroy();
            }
            
            // Limit to top 5 courses for better visualization
            const topCourses = data.courses.slice(0, 5);
            
            courseCompletionChart = new Chart(courseCompletionCtx, {
                type: 'bar',
                data: {
                    labels: topCourses.map(course => course.title),
                    datasets: [{
                        label: 'Completion Rate (%)',
                        data: topCourses.map(course => course.completion_rate),
                        backgroundColor: '#4e73df',
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }
    }
    
    // Function to update the member progress table
    function updateMemberProgressTable(members) {
        if (!members || members.length === 0) {
            $('#member-progress-tbody').html('<tr><td colspan="5">No member data available.</td></tr>');
            return;
        }
        
        let memberRows = '';
        
        members.forEach(function(member) {
            memberRows += `
                <tr>
                    <td>
                        <div class="member-info">
                            <img src="${member.avatar}" alt="${member.name}" class="member-avatar">
                            <div class="member-details">
                                <div class="member-name">${member.name}</div>
                                <div class="member-email">${member.email}</div>
                            </div>
                        </div>
                    </td>
                    <td>${member.courses_started}</td>
                    <td>${member.courses_completed}</td>
                    <td>
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width: ${member.overall_progress}%"></div>
                            <span class="progress-text">${member.overall_progress}%</span>
                        </div>
                    </td>
                    <td>${member.last_activity}</td>
                </tr>
            `;
        });
        
        $('#member-progress-tbody').html(memberRows);
    }
    
    // Function to update the course progress table
    function updateCourseProgressTable(courses) {
        if (!courses || courses.length === 0) {
            $('#course-progress-tbody').html('<tr><td colspan="4">No course data available.</td></tr>');
            return;
        }
        
        let courseRows = '';
        
        courses.forEach(function(course) {
            courseRows += `
                <tr>
                    <td>
                        <a href="${course.permalink}" target="_blank">${course.title}</a>
                    </td>
                    <td>${course.enrolled_members} / ${data.team.member_count}</td>
                    <td>
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width: ${course.completion_rate}%"></div>
                            <span class="progress-text">${course.completion_rate}%</span>
                        </div>
                    </td>
                    <td>
                        <button class="view-details-btn" data-course-id="${course.id}" data-course-title="${course.title}">
                            View Details
                        </button>
                    </td>
                </tr>
            `;
        });
        
        $('#course-progress-tbody').html(courseRows);
        
        // Add event listeners for the view details buttons
        $('.view-details-btn').on('click', function() {
            const courseId = $(this).data('course-id');
            const courseTitle = $(this).data('course-title');
            
            showCourseDetails(courseId, courseTitle);
        });
    }
    
    // Function to show course details modal
    function showCourseDetails(courseId, courseTitle) {
        // Find the course data
        const course = data.courses.find(c => c.id === courseId);
        
        if (!course) return;
        
        // Update modal title
        $('#modal-course-title').text(courseTitle);
        
        // Clear previous content
        $('#modal-member-progress').empty();
        
        // Get member progress for this course and sort by progress percentage (descending)
        const memberProgress = [];
        for (const userId in course.members_progress) {
            if (course.members_progress.hasOwnProperty(userId)) {
                const member = data.members.find(m => m.id == userId);
                if (member) {
                    memberProgress.push({
                        ...course.members_progress[userId],
                        name: member.name,
                        avatar: member.avatar,
                        email: member.email,
                        user_id: userId
                    });
                }
            }
        }
        
        // Sort by progress (descending)
        memberProgress.sort((a, b) => b.progress - a.progress);
        
        // Add member rows to the modal
        memberProgress.forEach(function(progress) {
            const completedStatus = progress.completed ? 
                '<span class="status-badge completed">Completed</span>' : 
                '<span class="status-badge in-progress">In Progress</span>';
                
            $('#modal-member-progress').append(`
                <tr>
                    <td>
                        <div class="member-info">
                            <img src="${progress.avatar}" alt="${progress.name}" class="member-avatar">
                            <div class="member-details">
                                <div class="member-name">${progress.name}</div>
                                <div class="member-email">${progress.email}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="progress-bar-container">
                            <div class="progress-bar" style="width: ${progress.progress}%"></div>
                            <span class="progress-text">${progress.progress}% ${completedStatus}</span>
                        </div>
                    </td>
                    <td>${progress.lessons_completed}</td>
                    <td>${progress.quizzes_passed}</td>
                    <td>${progress.start_date}</td>
                </tr>
            `);
        });
        
        // Show the modal
        $('#course-details-modal').css('display', 'block');
    }
});