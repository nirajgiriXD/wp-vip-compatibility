# Workflow

1. Create a New Branch:

- Create a new branch from the default branch (main):

```bash
git checkout main
git pull origin main
git checkout -b feat/your-feature-name
```

- Replace `your-feature-name` with a descriptive name for your new feature.

Alternatively, for fixing an issue:

```bash
git checkout main
git pull origin main
git checkout -b fix/your-issue-name
```

- Replace `your-issue-name` with a descriptive name for your issue.

2. Branch Naming Conventions:

- Ensure that branch names do not contain any capital letters.
- For adding a new feature, the branch name should start with `feat/` followed by the feature name.
- For fixing an issue, the branch name should start with `fix/` followed by the issue name.

3. Work on the Feature or Fix:

- Implement your feature or fix in the newly created branch.
- Make sure to commit your changes regularly.

4. Pull Latest Changes:

- Before creating a new branch or raising a pull request (PR), pull the latest changes from the remote repository:

```bash
git checkout main
git pull origin main
```

5. Create a Pull Request:

- Once your feature or fix is ready, push the branch to the remote repository:

```bash
git push origin feat/your-feature-name
```

or

```bash
git push origin fix/your-issue-name
```

- Create a pull request on the GitHub repository. Ensure that the pull request is targeting the main branch.

6. Pull Request Review:

- The main branch is protected, so all changes must go through a pull request.
- A reviewer will be assigned to review and approve the pull request.
- Make any necessary changes based on the reviewer's feedback.

7. Merge to Main:

- Once the pull request is approved, it can be merged into the main branch.

8. Repeat as Needed:

- Repeat the process for adding new features or fixing issues by creating new branches and pull requests.

## Note:

- Follow these steps consistently to maintain a clean and organized version history.
- Regularly pull the latest changes from the remote main branch to avoid conflicts.
- Adhere to branch naming conventions for clarity and consistency in the version history.
