            </div> <!-- End main-content -->
        </div> <!-- End content -->
    </div> <!-- End wrapper -->

    <!-- Scroll to Top Button -->
    <button class="scroll-to-top" title="Scroll to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- jQuery first -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Select2 JS (loaded after jQuery) -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <!-- NiceScroll -->
    <script src="https://cdn.jsdelivr.net/npm/jquery.nicescroll@3.7.6/jquery.nicescroll.min.js"></script>
    
    <!-- Custom JS -->
    <?php 
    $base_url = '/prdp_system'; // Make sure this matches your folder name
    ?>
    <script src="<?php echo $base_url; ?>/js/script.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize Select2 with error handling
        try {
            $('.select2').select2({
                width: '100%',
                theme: 'default',
                placeholder: 'Select an option',
                allowClear: true
            });
            console.log('Select2 initialized successfully');
        } catch(e) {
            console.log('Select2 initialization failed:', e);
        }

        // Initialize DataTables
        try {
            $('.datatable').DataTable({
                pageLength: 10,
                responsive: true,
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search..."
                }
            });
            console.log('DataTables initialized successfully');
        } catch(e) {
            console.log('DataTables initialization failed:', e);
        }

        // Sidebar Toggle
        $('#sidebarCollapse').on('click', function() {
            $('#sidebar, #content').toggleClass('active');
            
            // Save state to localStorage
            localStorage.setItem('sidebarCollapsed', $('#sidebar').hasClass('active'));
        });

        // Check saved sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            $('#sidebar, #content').addClass('active');
        }

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Scroll to top functionality
        $(window).scroll(function() {
            if ($(this).scrollTop() > 100) {
                $('.scroll-to-top').fadeIn();
            } else {
                $('.scroll-to-top').fadeOut();
            }
        });

        $('.scroll-to-top').click(function() {
            $('html, body').animate({scrollTop: 0}, 600);
            return false;
        });

        // Handle window resize
        $(window).resize(function() {
            if ($(window).width() <= 768) {
                if (!localStorage.getItem('sidebarCollapsed')) {
                    $('#sidebar, #content').addClass('active');
                }
            } else {
                if (localStorage.getItem('sidebarCollapsed') !== 'true') {
                    $('#sidebar, #content').removeClass('active');
                }
            }
        });

        // Trigger resize on load
        $(window).resize();
    });
    </script>
</body>
</html>