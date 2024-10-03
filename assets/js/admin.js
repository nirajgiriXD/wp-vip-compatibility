document.addEventListener("DOMContentLoaded", function () {
    // Get all tab links
    const tabLinks = document.querySelectorAll(".tab-link");

    // Add event listeners to each tab button
    tabLinks.forEach(function (tabLink) {
        tabLink.addEventListener("click", function (event) {
            openTab(event, tabLink.getAttribute("data-tab"));
        });
    });

    // Function to handle tab switching
    function openTab(evt, tabName) {
        // Hide all tab content
        const tabContent = document.querySelectorAll(".wvc-tab-content");
        tabContent.forEach(function (content) {
            content.style.display = "none";
            content.classList.remove("active");
        });

        // Remove active class from all tab links
        tabLinks.forEach(function (link) {
            link.classList.remove("active");
        });

        // Show the clicked tab's content and mark the tab as active
        const selectedTab = document.getElementById(tabName);
        selectedTab.style.display = "block";
        selectedTab.classList.add("active");
        evt.currentTarget.classList.add("active");
    }
});
