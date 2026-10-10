document.addEventListener("DOMContentLoaded", () => {
    // 1. Sidebar Toggle Logic
    let sidebar = document.querySelector(".sidebar");
    let sidebarBtn = document.querySelector(".sidebarBtn");
    
    if (sidebarBtn) {
        sidebarBtn.onclick = function() {
            sidebar.classList.toggle("active");
            if(sidebar.classList.contains("active")){
                sidebarBtn.classList.replace("bx-menu" ,"bx-menu-alt-right");
            } else {
                sidebarBtn.classList.replace("bx-menu-alt-right", "bx-menu");
            }
        }
    }

    // 2. Active Link State and Title Update
    const currentLocation = location.href;
    const menuItems = document.querySelectorAll('.nav-links li a');
    let pageTitle = document.getElementById("page-title");
    
    // Default Title mapped for pages
    let foundActive = false;
    
    menuItems.forEach(item => {
        // Simple string match for the active active window
        if (currentLocation.includes(item.getAttribute('href'))) {
            item.classList.add("active");
            
            let linkText = item.querySelector(".links_name").innerText;
            if (pageTitle) {
                pageTitle.innerText = linkText;
            }
            foundActive = true;
        }
    });

    if (!foundActive && pageTitle && currentLocation.includes("guest_details.php")) {
        pageTitle.innerText = "Guest Details";
    }

});
